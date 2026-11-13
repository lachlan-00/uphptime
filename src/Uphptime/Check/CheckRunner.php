<?php

declare(strict_types=1);

/**
 * vim:set softtabstop=4 shiftwidth=4 expandtab:
 *
 * LICENSE: GNU Affero General Public License, version 3 (AGPL-3.0-or-later)
 * Copyright Lachlan de Waard <lachlan.00@gmail.com>, 2026
 *
 * This program is free software: you can redistribute it and/or modify
 * it under the terms of the GNU Affero General Public License as published by
 * the Free Software Foundation, either version 3 of the License, or
 * (at your option) any later version.
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 * GNU Affero General Public License for more details.
 *
 * You should have received a copy of the GNU Affero General Public License
 * along with this program.  If not, see <https://www.gnu.org/licenses/>.
 *
 */

namespace Uphptime\Check;

use DateTimeImmutable;
use Uphptime\Api\ChallengeToken\ChallengeTokenStoreInterface;
use Uphptime\Config\Config;
use Uphptime\History\HistoryRecord;
use Uphptime\History\HistoryRecordRole;
use Uphptime\Peer\Peer;
use Uphptime\Peer\PeerSelectorInterface;
use Uphptime\Reporting\RetentionPruner;
use Uphptime\Reporting\RollupBuilder;
use Uphptime\Scheduling\RunBudget;
use Uphptime\Site\CheckType;
use Uphptime\Site\Site;
use Uphptime\Site\SiteRuntimeState;
use Uphptime\Site\SiteStateMachine;
use Uphptime\Storage\HistoryRepositoryInterface;
use Uphptime\Storage\SiteRepositoryInterface;
use Uphptime\Support\ClockInterface;

/**
 * Orchestrates one scheduled pass: closes finished rollups, prunes, then checks due sites within
 * budget, delegating to a peer when one is usable and falling back to a self-check otherwise
 * (a node with every peer currently circuit-broken by
 * reputation falls back the same way, since neither case leaves a candidate to pick). A peer Fail is
 * confirmed by a genuinely different peer (or a self-check, at exactly one usable peer) before it
 * ever reaches the state machine; an accepted Pass may separately be canary-sampled afterward.
 * SiteStateMachine is never aware of which path, or how many sub-checks, produced its CheckOutcome.
 */
final class CheckRunner
{
    /** @var array<string, CheckerInterface> */
    private readonly array $checkers;

    public function __construct(
        private readonly SiteRepositoryInterface $siteRepository,
        private readonly HistoryRepositoryInterface $historyRepository,
        private readonly SiteStateMachine $stateMachine,
        private readonly RollupBuilder $rollupBuilder,
        private readonly RetentionPruner $retentionPruner,
        private readonly ClockInterface $clock,
        private readonly Config $config,
        private readonly ChallengeTokenStoreInterface $challengeTokenStore,
        CheckerInterface $httpChecker,
        CheckerInterface $httpKeywordChecker,
        CheckerInterface $tcpChecker,
        CheckerInterface $icmpChecker,
        private readonly ?PeerSelectorInterface $peerSelector = null,
        private readonly ?PeerResultAcquirer $peerResultAcquirer = null,
        private readonly ?DownConfirmer $downConfirmer = null,
        private readonly ?CanarySampler $canarySampler = null,
    ) {
        $this->checkers = [
            CheckType::Http->value => $httpChecker,
            CheckType::HttpKeyword->value => $httpKeywordChecker,
            CheckType::Tcp->value => $tcpChecker,
            CheckType::Icmp->value => $icmpChecker,
        ];
    }

    public function run(?int $budgetSecondsOverride = null): RunSummary
    {
        $startedAtMs = $this->nowMs();
        $budget      = new RunBudget($this->clock, $budgetSecondsOverride ?? $this->config->runBudgetSeconds);

        $rollupsClosed = $this->rollupBuilder->closeFinishedDays();
        $prunedFiles   = $this->retentionPruner->prune($this->config->retentionDays);

        $checked = 0;
        $skipped = 0;

        foreach ($this->dueSitesInOrder() as $site) {
            if ($budget->isExhausted()) {
                $skipped++;
                continue;
            }

            $this->checkOne($site);
            $checked++;
        }

        return new RunSummary($checked, $skipped, $rollupsClosed, $prunedFiles, $this->nowMs() - $startedAtMs);
    }

    /** @param array<string, mixed>|null $evidence */
    private function applyResult(Site $site, CheckResult $result, DateTimeImmutable $checkedAt, string $source, ?array $evidence, string $attestation, HistoryRecordRole $role = HistoryRecordRole::Primary, ?Peer $peer = null): void
    {
        $newState = $this->transitionFor($site, $result, $checkedAt);
        $this->persistTransition($site, $newState, $result, $checkedAt, $source, $evidence, $attestation, $role, flagged: false, peer: $peer);
    }

    private function checkOne(Site $site): void
    {
        $checkedAt = $this->clock->now();

        if ($this->hasUsablePeer()) {
            // Issued fresh once per check cycle, before the first contact, so a genuinely different
            // confirmation/canary peer probing the same site within this cycle sees the same live
            // token - a self-check never issues one, since there's no second party to attest to.
            if ($site->usesChallengeToken()) {
                $this->challengeTokenStore->issue($site->id, $checkedAt, $this->config->challengeTokenTtlSeconds);
            }

            $this->checkOneViaPeer($site, $checkedAt);

            return;
        }

        $this->checkLocally($site, $checkedAt);
    }

    // A check run by this node itself: the whole path with no usable peer, and the last resort when
    // peers were tried but none returned a conclusive result.
    private function checkLocally(Site $site, DateTimeImmutable $checkedAt): void
    {
        $checker = $this->checkers[$site->checkType->value];

        try {
            $result = $checker->check($site, (float) $this->config->requestTimeoutSeconds);
        } catch (CheckConfigurationException $exception) {
            $result = CheckResult::error($exception->getMessage());
        }

        // With local_failure_counts_as_down disabled, a connection-level failure with nobody to
        // corroborate it is recorded as an error rather than downtime (it may be this node's network).
        if (!$this->config->localFailureCountsAsDown && $result->isUnverifiedNetworkFailure()) {
            $result = $result->asUnverifiedError();
        }

        $this->applyResult($site, $result, $checkedAt, 'self', null, 'none');
    }

    private function checkOneViaPeer(Site $site, DateTimeImmutable $checkedAt): void
    {
        $acquired = $this->peerResultAcquirer?->acquire($site, [], (float) $this->config->peerRequestTimeoutSeconds);

        if ($acquired === null || $acquired->result->outcome === CheckOutcome::Error) {
            // No peer produced a conclusive result within max_delegates_per_acquisition attempts:
            // fall back to checking from this node rather than recording an unmeasured cycle.
            $this->checkLocally($site, $checkedAt);

            return;
        }

        if ($acquired->result->outcome === CheckOutcome::Fail) {
            $this->resolveFail($site, $acquired, $checkedAt);

            return;
        }

        $this->resolvePass($site, $acquired, $checkedAt);
    }

    // Enabled sites whose next_due_at has passed, soonest-due first.
    /** @return Site[] */
    private function dueSitesInOrder(): array
    {
        $now = $this->clock->now();
        $due = array_values(array_filter(
            $this->siteRepository->all(),
            static fn(Site $site): bool => $site->enabled && $site->state->nextDueAt <= $now,
        ));

        usort($due, static fn(Site $a, Site $b): int => $a->state->nextDueAt <=> $b->state->nextDueAt);

        return $due;
    }

    // Whether at least one peer is currently available to delegate to - excludes any peer the
    // circuit breaker has excluded, which is what makes "every peer currently excluded" fall back to
    // the self-check path exactly like "no peers configured" does.
    private function hasUsablePeer(): bool
    {
        return $this->peerSelector !== null && $this->peerResultAcquirer !== null && $this->peerSelector->pick([]) !== null;
    }

    private function nowMs(): int
    {
        return (int) round($this->clock->now()->format('U.u') * 1000);
    }

    /** @param array<string, mixed>|null $evidence */
    private function persistTransition(Site $site, SiteRuntimeState $newState, CheckResult $result, DateTimeImmutable $checkedAt, string $source, ?array $evidence, string $attestation, HistoryRecordRole $role, bool $flagged, ?Peer $peer = null): void
    {
        $this->historyRepository->append($site->id, new HistoryRecord(
            ts: $checkedAt,
            siteId: $site->id,
            checkType: $site->checkType,
            source: $source,
            outcome: $result->outcome,
            httpStatus: $result->httpStatus,
            responseTimeMs: $result->responseTimeMs,
            keywordMatched: $result->keywordMatched,
            error: $result->error,
            stateBefore: $site->state->status,
            stateAfter: $newState->status,
            excludedByMaintenance: $site->isUnderMaintenanceAt($checkedAt),
            evidence: $evidence,
            attestation: $attestation,
            role: $role,
            flagged: $flagged,
            peerId: $peer?->id,
            peerLabel: $peer?->label,
        ));

        $this->siteRepository->save($site->withState($newState));
    }

    /** @param array<string, mixed>|null $evidence */
    private function recordWithoutMutating(Site $site, CheckResult $result, DateTimeImmutable $checkedAt, string $source, ?array $evidence, string $attestation, HistoryRecordRole $role, ?Peer $peer = null): void
    {
        $status = $site->state->status;

        $this->historyRepository->append($site->id, new HistoryRecord(
            ts: $checkedAt,
            siteId: $site->id,
            checkType: $site->checkType,
            source: $source,
            outcome: $result->outcome,
            httpStatus: $result->httpStatus,
            responseTimeMs: $result->responseTimeMs,
            keywordMatched: $result->keywordMatched,
            error: $result->error,
            stateBefore: $status,
            stateAfter: $status,
            excludedByMaintenance: $site->isUnderMaintenanceAt($checkedAt),
            evidence: $evidence,
            attestation: $attestation,
            role: $role,
            peerId: $peer?->id,
            peerLabel: $peer?->label,
        ));
    }

    // The primary peer's failure must be confirmed before it can affect the monitor's state.
    private function resolveFail(Site $site, AcquiredPeerResult $primary, DateTimeImmutable $checkedAt): void
    {
        $primarySource = $primary->source();

        if ($this->downConfirmer === null) {
            $this->applyResult($site, CheckResult::error('peer_failure_unconfirmed: ' . ($primary->result->error ?? 'unknown error')), $checkedAt, $primarySource, $primary->evidence, $primary->attestation, peer: $primary->peer);

            return;
        }

        $confirmation = $this->downConfirmer->confirm($site, $primary, (float) $this->config->peerRequestTimeoutSeconds);

        $result      = $confirmation->result;
        $source      = $confirmation->source;
        $attestation = $confirmation->attestation;

        if (!$confirmation->confirmed || $result === null || $source === null || $attestation === null || $result->outcome === CheckOutcome::Error) {
            $this->applyResult($site, CheckResult::error('peer_failure_unconfirmed: ' . ($primary->result->error ?? 'unknown error')), $checkedAt, $primarySource, $primary->evidence, $primary->attestation, peer: $primary->peer);

            return;
        }

        if ($result->outcome === CheckOutcome::Pass) {
            $this->recordWithoutMutating($site, $primary->result, $checkedAt, $primarySource, $primary->evidence, $primary->attestation, HistoryRecordRole::Confirmation, $primary->peer);
            $this->applyResult($site, $result, $checkedAt, $source, $confirmation->evidence, $attestation, HistoryRecordRole::Primary, $confirmation->peer);

            return;
        }

        $this->recordWithoutMutating($site, $primary->result, $checkedAt, $primarySource, $primary->evidence, $primary->attestation, HistoryRecordRole::Primary, $primary->peer);
        $this->applyResult($site, $result, $checkedAt, $source, $confirmation->evidence, $attestation, HistoryRecordRole::Confirmation, $confirmation->peer);
    }

    // An accepted Pass is applied immediately and unconditionally; canary sampling is a separate,
    // never-blocking integrity check on a result already acted on. Its records are appended only
    // after the primary record, so a disagreement can flag the primary before it is ever written.
    private function resolvePass(Site $site, AcquiredPeerResult $primary, DateTimeImmutable $checkedAt): void
    {
        $source   = $primary->source();
        $newState = $this->transitionFor($site, $primary->result, $checkedAt);

        $canaryOutcome = $this->canarySampler?->sampleAfterPass($site, $primary, $newState->status, $checkedAt);
        $flagged       = $canaryOutcome->flagged ?? false;

        $this->persistTransition($site, $newState, $primary->result, $checkedAt, $source, $primary->evidence, $primary->attestation, HistoryRecordRole::Primary, $flagged, $primary->peer);

        foreach ($canaryOutcome->extraRecords ?? [] as $extraRecord) {
            $this->historyRepository->append($site->id, $extraRecord);
        }
    }

    private function transitionFor(Site $site, CheckResult $result, DateTimeImmutable $checkedAt): SiteRuntimeState
    {
        $newState = $result->outcome === CheckOutcome::Error
            ? $site->state->withErrorChecked($checkedAt)
            : $this->stateMachine->apply($site->state, $result->outcome, $checkedAt);

        $interval = $site->intervalSeconds($this->config->checkIntervalSeconds);

        return $newState->withNextDueAt($checkedAt->modify(sprintf('+%d seconds', $interval)));
    }
}

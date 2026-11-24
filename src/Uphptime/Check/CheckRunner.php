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
use Uphptime\Storage\MaintenanceScheduleRepositoryInterface;
use Uphptime\Storage\SiteRepositoryInterface;
use Uphptime\Support\ClockInterface;

/**
 * Orchestrates one scheduled pass: closes finished rollups, prunes, then checks due (or due within
 * `check_due_grace_seconds`) sites within budget, delegating to a peer when one is usable and
 * falling back to a self-check otherwise.
 */
final class CheckRunner
{
    /** @var array<string, CheckerInterface> */
    private readonly array $checkers;

    public function __construct(
        private readonly SiteRepositoryInterface $siteRepository,
        private readonly HistoryRepositoryInterface $historyRepository,
        private readonly MaintenanceScheduleRepositoryInterface $maintenanceScheduleRepository,
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

    // Read-only; lets a lock-blocked invocation report due-but-unchecked sites instead of a bare error.
    public function overlapSnapshot(): RunSummary
    {
        $sites = $this->classifySites($this->clock->now());

        return new RunSummary($sites['total'], 0, 0, count($sites['due']), 0, 0, 0, $sites['disabled'], $sites['notDue']);
    }

    public function run(?int $budgetSecondsOverride = null): RunSummary
    {
        $startedAtMs = $this->nowMs();
        // Anchors every site in a run to one instant, so a slow site queued late doesn't see its own next slot as already passed.
        $now    = $this->clock->now();
        $budget = new RunBudget($this->clock, $budgetSecondsOverride ?? $this->config->runBudgetSeconds);

        $rollupsClosed = $this->rollupBuilder->closeFinishedDays();
        $prunedFiles   = $this->retentionPruner->prune($this->config->retentionDays);
        $sites         = $this->classifySites($now);

        $checked = 0;
        $skipped = 0;

        foreach ($sites['due'] as $site) {
            if ($budget->isExhausted()) {
                $skipped++;
                continue;
            }

            $this->checkOne($site, $now);
            $checked++;
        }

        return new RunSummary($sites['total'], $checked, $skipped, 0, $rollupsClosed, $prunedFiles, $this->nowMs() - $startedAtMs, $sites['disabled'], $sites['notDue']);
    }

    /** @param array<string, mixed>|null $evidence */
    private function applyResult(Site $site, CheckResult $result, DateTimeImmutable $checkedAt, DateTimeImmutable $schedulingNow, string $source, ?array $evidence, string $attestation, HistoryRecordRole $role = HistoryRecordRole::Primary, ?Peer $peer = null): void
    {
        $newState = $this->transitionFor($site, $result, $checkedAt, $schedulingNow);
        $this->persistTransition($site, $newState, $result, $checkedAt, $source, $evidence, $attestation, $role, flagged: false, peer: $peer);
    }

    // The whole path with no usable peer, and the last resort when peers were tried but inconclusive.
    private function checkLocally(Site $site, DateTimeImmutable $checkedAt, DateTimeImmutable $schedulingNow): void
    {
        $checker = $this->checkers[$site->checkType->value];

        try {
            $result = $checker->check($site, (float) $this->config->requestTimeoutSeconds);
        } catch (CheckConfigurationException $checkConfigurationException) {
            $result = CheckResult::error($checkConfigurationException->getMessage());
        }

        // With local_failure_counts_as_down disabled, an uncorroborated connection failure is an error, not downtime.
        if (!$this->config->localFailureCountsAsDown && $result->isUnverifiedNetworkFailure()) {
            $result = $result->asUnverifiedError();
        }

        $this->applyResult($site, $result, $checkedAt, $schedulingNow, 'self', null, 'none');
    }

    private function checkOne(Site $site, DateTimeImmutable $schedulingNow): void
    {
        $checkedAt = $this->clock->now();

        if ($this->hasUsablePeer()) {
            // Issued fresh per cycle so a confirmation/canary peer sees the same live token this cycle.
            if ($site->usesChallengeToken()) {
                $this->challengeTokenStore->issue($site->id, $checkedAt, $this->config->challengeTokenTtlSeconds);
            }

            $this->checkOneViaPeer($site, $checkedAt, $schedulingNow);

            return;
        }

        $this->checkLocally($site, $checkedAt, $schedulingNow);
    }

    private function checkOneViaPeer(Site $site, DateTimeImmutable $checkedAt, DateTimeImmutable $schedulingNow): void
    {
        $acquired = $this->peerResultAcquirer?->acquire($site, [], (float) $this->config->peerRequestTimeoutSeconds);

        if (!$acquired instanceof \Uphptime\Check\AcquiredPeerResult || $acquired->result->outcome === CheckOutcome::Error) {
            // No peer produced a conclusive result, so fall back rather than record an unmeasured cycle.
            $this->checkLocally($site, $checkedAt, $schedulingNow);

            return;
        }

        if ($acquired->result->outcome === CheckOutcome::Fail) {
            $this->resolveFail($site, $acquired, $checkedAt, $schedulingNow);

            return;
        }

        $this->resolvePass($site, $acquired, $checkedAt, $schedulingNow);
    }

    // Splits every configured site into disabled, due (soonest-due first) and not-yet-due, so total = due + disabled + notDue always.
    /** @return array{total: int, due: Site[], disabled: int, notDue: int} */
    private function classifySites(DateTimeImmutable $now): array
    {
        $sites    = $this->siteRepository->all();
        $due      = [];
        $disabled = 0;
        $notDue   = 0;
        // A site due only a few seconds from now is treated as due: cron/process-startup jitter otherwise
        // leaves a fixed-phase site landing just past "now" on some ticks, stranding it a whole interval.
        $dueThreshold = $now->modify(sprintf('+%d seconds', $this->config->checkDueGraceSeconds));

        foreach ($sites as $site) {
            if (!$site->enabled) {
                $disabled++;
            } elseif ($site->state->nextDueAt <= $dueThreshold) {
                $due[] = $site;
            } else {
                $notDue++;
            }
        }

        usort($due, static fn(Site $a, Site $b): int => $a->state->nextDueAt <=> $b->state->nextDueAt);

        return ['total' => count($sites), 'due' => $due, 'disabled' => $disabled, 'notDue' => $notDue];
    }

    // Whether at least one peer is available to delegate to, excluding any circuit-broken by reputation.
    private function hasUsablePeer(): bool
    {
        return $this->peerSelector instanceof \Uphptime\Peer\PeerSelectorInterface && $this->peerResultAcquirer instanceof \Uphptime\Check\PeerResultAcquirer && $this->peerSelector->pick([]) instanceof \Uphptime\Peer\Peer;
    }

    // Steps from the previous slot in whole intervals past $schedulingNow, not $checkedAt, so a slow run can't bake its lag into the schedule.
    // A slot far behind after real downtime fast-forwards to the next free one, so a gap never bursts into a run of back-to-back checks.
    private function nextDueAtAfter(DateTimeImmutable $previousDueAt, DateTimeImmutable $schedulingNow, int $interval): DateTimeImmutable
    {
        $nextDueAt = $previousDueAt;

        do {
            $nextDueAt = $nextDueAt->modify(sprintf('+%d seconds', $interval));
        } while ($nextDueAt <= $schedulingNow);

        return $nextDueAt;
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
            excludedByMaintenance: $site->isUnderMaintenanceAt($checkedAt, $this->maintenanceScheduleRepository->all()),
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
            excludedByMaintenance: $site->isUnderMaintenanceAt($checkedAt, $this->maintenanceScheduleRepository->all()),
            evidence: $evidence,
            attestation: $attestation,
            role: $role,
            peerId: $peer?->id,
            peerLabel: $peer?->label,
        ));
    }

    // The primary peer's failure must be confirmed before it can affect the monitor's state.
    private function resolveFail(Site $site, AcquiredPeerResult $primary, DateTimeImmutable $checkedAt, DateTimeImmutable $schedulingNow): void
    {
        $primarySource = $primary->source();

        if (!$this->downConfirmer instanceof \Uphptime\Check\DownConfirmer) {
            $this->applyResult($site, CheckResult::error('peer_failure_unconfirmed: ' . ($primary->result->error ?? 'unknown error')), $checkedAt, $schedulingNow, $primarySource, $primary->evidence, $primary->attestation, peer: $primary->peer);

            return;
        }

        $confirmation = $this->downConfirmer->confirm($site, $primary, (float) $this->config->peerRequestTimeoutSeconds);

        $result      = $confirmation->result;
        $source      = $confirmation->source;
        $attestation = $confirmation->attestation;

        if (!$confirmation->confirmed || !$result instanceof \Uphptime\Check\CheckResult || $source === null || $attestation === null || $result->outcome === CheckOutcome::Error) {
            $this->applyResult($site, CheckResult::error('peer_failure_unconfirmed: ' . ($primary->result->error ?? 'unknown error')), $checkedAt, $schedulingNow, $primarySource, $primary->evidence, $primary->attestation, peer: $primary->peer);

            return;
        }

        if ($result->outcome === CheckOutcome::Pass) {
            $this->recordWithoutMutating($site, $primary->result, $checkedAt, $primarySource, $primary->evidence, $primary->attestation, HistoryRecordRole::Confirmation, $primary->peer);
            $this->applyResult($site, $result, $checkedAt, $schedulingNow, $source, $confirmation->evidence, $attestation, HistoryRecordRole::Primary, $confirmation->peer);

            return;
        }

        $this->recordWithoutMutating($site, $primary->result, $checkedAt, $primarySource, $primary->evidence, $primary->attestation, HistoryRecordRole::Primary, $primary->peer);
        $this->applyResult($site, $result, $checkedAt, $schedulingNow, $source, $confirmation->evidence, $attestation, HistoryRecordRole::Confirmation, $confirmation->peer);
    }

    // An accepted Pass applies immediately; canary records append only after it, so a disagreement can flag it.
    private function resolvePass(Site $site, AcquiredPeerResult $primary, DateTimeImmutable $checkedAt, DateTimeImmutable $schedulingNow): void
    {
        $source   = $primary->source();
        $newState = $this->transitionFor($site, $primary->result, $checkedAt, $schedulingNow);

        $canaryOutcome = $this->canarySampler?->sampleAfterPass($site, $primary, $newState->status, $checkedAt);
        $flagged       = $canaryOutcome->flagged ?? false;

        $this->persistTransition($site, $newState, $primary->result, $checkedAt, $source, $primary->evidence, $primary->attestation, HistoryRecordRole::Primary, $flagged, $primary->peer);

        foreach ($canaryOutcome->extraRecords ?? [] as $extraRecord) {
            $this->historyRepository->append($site->id, $extraRecord);
        }
    }

    private function transitionFor(Site $site, CheckResult $result, DateTimeImmutable $checkedAt, DateTimeImmutable $schedulingNow): SiteRuntimeState
    {
        $newState = $result->outcome === CheckOutcome::Error
            ? $site->state->withErrorChecked($checkedAt)
            : $this->stateMachine->apply($site->state, $result->outcome, $checkedAt);

        $interval  = $site->intervalSeconds($this->config->checkIntervalSeconds);
        $nextDueAt = $this->nextDueAtAfter($site->state->nextDueAt, $schedulingNow, $interval);

        return $newState->withNextDueAt($nextDueAt);
    }
}

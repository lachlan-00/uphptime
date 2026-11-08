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
use Uphptime\Config\Config;
use Uphptime\History\HistoryRecord;
use Uphptime\History\HistoryRecordRole;
use Uphptime\Peer\Peer;
use Uphptime\Peer\PeerReputationScorer;
use Uphptime\Peer\PeerSelectorInterface;
use Uphptime\Site\Site;
use Uphptime\Site\SiteState;
use Uphptime\Storage\PeerReputationRepositoryInterface;
use Uphptime\Support\ClockInterface;
use Uphptime\Support\RandomInterface;

/**
 * Re-runs a configurable fraction of accepted passes through a second, different peer. Agreement is
 * silent; a disagreement is scored against both peers and settled by a third-peer tie-break, which
 * flags the already-applied pass but never itself touches site state - it is a retrospective
 * integrity check on a result already acted on, not a second vote on current status.
 */
final class CanarySampler
{
    /** @param array<string, CheckerInterface> $checkers */
    public function __construct(
        private readonly RandomInterface $random,
        private readonly PeerSelectorInterface $peerSelector,
        private readonly PeerResultAcquirer $peerResultAcquirer,
        private readonly PeerReputationRepositoryInterface $reputationRepository,
        private readonly PeerReputationScorer $scorer,
        private readonly ClockInterface $clock,
        private readonly Config $config,
        private readonly array $checkers,
        private readonly float $sampleRate,
    ) {}

    public function sampleAfterPass(Site $site, AcquiredPeerResult $primary, SiteState $resultingStatus, DateTimeImmutable $checkedAt): CanarySampleOutcome
    {
        if ($this->random->probability() >= $this->sampleRate) {
            return CanarySampleOutcome::notSampled();
        }

        $primaryPeerId = (string) $primary->peer?->id;
        $canary        = $this->acquireCandidate($site, [$primaryPeerId]);

        if ($canary === null) {
            $record = $this->record($site, CheckResult::error('canary_unavailable'), $checkedAt, 'canary', null, 'none', $resultingStatus);

            return CanarySampleOutcome::attemptFailed($record);
        }

        $canaryRecord = $this->record($site, $canary->result, $checkedAt, $canary->source(), $canary->evidence, $canary->attestation, $resultingStatus, peer: $canary->peer);

        if ($canary->result->outcome !== CheckOutcome::Fail) {
            return CanarySampleOutcome::agreed($canaryRecord);
        }

        $canaryPeerId = (string) $canary->peer?->id;
        $this->recordDisagreement($primaryPeerId);
        $this->recordDisagreement($canaryPeerId);

        $tieBreak = $this->acquireCandidate($site, array_values(array_filter([$primaryPeerId, $canaryPeerId], static fn(string $id): bool => $id !== '')), allowSelfFallback: false);

        if ($tieBreak === null) {
            return CanarySampleOutcome::disagreedUnresolved($canaryRecord);
        }

        $tieBreakRecord = $this->record($site, $tieBreak->result, $checkedAt, $tieBreak->source(), $tieBreak->evidence, $tieBreak->attestation, $resultingStatus, HistoryRecordRole::TieBreak, $tieBreak->peer);

        if ($tieBreak->result->outcome !== CheckOutcome::Fail) {
            $this->recordDisagreement($canaryPeerId);
            $this->recordGood($primaryPeerId);

            return CanarySampleOutcome::disagreedVindicatedOriginal($canaryRecord, $tieBreakRecord);
        }

        $this->recordFalsePass($primaryPeerId);
        $this->recordGood($canaryPeerId);

        return CanarySampleOutcome::disagreedConfirmedFalsePass($canaryRecord, $tieBreakRecord);
    }

    // The canary pick self-falls-back at zero other usable peers, same principle as down-confirmation.
    // The tie-break pick must NOT self-fallback: self-probing to break a tie between two peers that
    // already disagreed adds no independence, so with no third peer it stays genuinely unresolved.
    /** @param string[] $excludePeerIds */
    private function acquireCandidate(Site $site, array $excludePeerIds, bool $allowSelfFallback = true): ?AcquiredPeerResult
    {
        if ($this->peerSelector->pick($excludePeerIds) === null) {
            return $allowSelfFallback ? new AcquiredPeerResult(null, null, $this->selfCheck($site), null, 'none') : null;
        }

        return $this->peerResultAcquirer->acquire($site, $excludePeerIds, (float) $this->config->peerRequestTimeoutSeconds);
    }

    /** @param array<string, mixed>|null $evidence */
    private function record(Site $site, CheckResult $result, DateTimeImmutable $checkedAt, string $source, ?array $evidence, string $attestation, SiteState $status, HistoryRecordRole $role = HistoryRecordRole::Canary, ?Peer $peer = null): HistoryRecord
    {
        return new HistoryRecord(
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
        );
    }

    private function recordDisagreement(string $peerId): void
    {
        if ($peerId === '') {
            return;
        }

        $now        = $this->clock->now();
        $reputation = $this->reputationRepository->get($peerId);
        $this->reputationRepository->save($this->scorer->recordDisagreement($reputation, $now));
    }

    private function recordFalsePass(string $peerId): void
    {
        if ($peerId === '') {
            return;
        }

        $now        = $this->clock->now();
        $reputation = $this->reputationRepository->get($peerId);
        $this->reputationRepository->save($this->scorer->recordFalsePass($reputation, $now));
    }

    private function recordGood(string $peerId): void
    {
        if ($peerId === '') {
            return;
        }

        $now        = $this->clock->now();
        $reputation = $this->reputationRepository->get($peerId);
        $this->reputationRepository->save($this->scorer->recordGoodResponse($reputation, $now));
    }

    private function selfCheck(Site $site): CheckResult
    {
        $checker = $this->checkers[$site->checkType->value];

        try {
            return $checker->check($site, (float) $this->config->requestTimeoutSeconds);
        } catch (CheckConfigurationException $exception) {
            return CheckResult::error($exception->getMessage());
        }
    }
}

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

namespace Uphptime\Peer;

use DateTimeImmutable;
use Uphptime\Config\Config;

/**
 * Moves a peer's score with a bounded EWMA (score = score + alpha * (target - score)), and decides
 * circuit-breaker exclusion/recovery. Confirmation disagreement is the gentlest event (a plain Fail
 * vs Pass between two peers about a live target is the routine case confirmation exists to produce,
 * so it must never alone drive exclusion); a tie-break-confirmed false pass is the harshest.
 */
final class PeerReputationScorer
{
    private const CONFIRMATION_DISAGREE_TARGET = 60.0;
    private const EXCLUDED_TARGET              = 0.0;
    private const GOOD_TARGET                  = 100.0;

    public function __construct(private readonly Config $config) {}

    public function isExcluded(PeerReputation $reputation, DateTimeImmutable $now): bool
    {
        return $reputation->isExcluded($now);
    }

    // The routine, expected case: two peers genuinely disagreed (Fail vs Pass) about a live target.
    public function recordConfirmationDisagreement(PeerReputation $reputation, DateTimeImmutable $now): PeerReputation
    {
        return $this->applyEvent($reputation, self::CONFIRMATION_DISAGREE_TARGET, $this->config->peerReputationConfirmationDisagreementAlpha, $now);
    }

    public function recordDisagreement(PeerReputation $reputation, DateTimeImmutable $now): PeerReputation
    {
        return $this->applyEvent($reputation, self::EXCLUDED_TARGET, $this->config->peerReputationDisagreementAlpha, $now);
    }

    // A tie-break confirmed this peer served a false pass - the harshest, deliberately asymmetric event.
    public function recordFalsePass(PeerReputation $reputation, DateTimeImmutable $now): PeerReputation
    {
        return $this->applyEvent($reputation, self::EXCLUDED_TARGET, $this->config->peerReputationFalsePassAlpha, $now);
    }

    public function recordGoodResponse(PeerReputation $reputation, DateTimeImmutable $now): PeerReputation
    {
        return $this->applyEvent($reputation, self::GOOD_TARGET, $this->config->peerReputationEwmaAlpha, $now);
    }

    public function recordNonResponse(PeerReputation $reputation, DateTimeImmutable $now): PeerReputation
    {
        return $this->applyEvent($reputation, self::EXCLUDED_TARGET, $this->config->peerReputationEwmaAlpha, $now);
    }

    private function applyEvent(PeerReputation $reputation, float $target, float $alpha, DateTimeImmutable $now): PeerReputation
    {
        $reputation = $this->recoverIfCooldownElapsed($reputation, $now);

        $score      = max(0.0, min(100.0, $reputation->score + $alpha * ($target - $reputation->score)));
        $reputation = $reputation->withScore($score, $now);

        if ($score >= $this->config->peerReputationExclusionThreshold) {
            return $reputation->withExclusionCleared();
        }

        $consecutive     = $reputation->consecutiveExclusions + 1;
        $cooldownSeconds = (int) min(
            $this->config->peerReputationCooldownSeconds * ($this->config->peerReputationCooldownBackoffMultiplier ** ($consecutive - 1)),
            $this->config->peerReputationMaxCooldownSeconds,
        );

        return $reputation->withExclusion($now->modify(sprintf('+%d seconds', $cooldownSeconds)), $consecutive);
    }

    // A cooldown that has just elapsed lands on probation, not neutral, so one more good response
    // clears it comfortably while one more bad response re-excludes it and lengthens the next cooldown.
    private function recoverIfCooldownElapsed(PeerReputation $reputation, DateTimeImmutable $now): PeerReputation
    {
        if ($reputation->excludedUntil === null || $reputation->excludedUntil > $now) {
            return $reputation;
        }

        return $reputation->onProbation($this->config->peerReputationProbationScore);
    }
}

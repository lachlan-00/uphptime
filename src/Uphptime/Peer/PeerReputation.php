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

/**
 * One peer's local reputation: a bounded [0, 100] score plus the circuit-breaker's exclusion state.
 * A peer never yet scored is neutral (100), not a missing/null record.
 */
final class PeerReputation
{
    public function __construct(
        public readonly string $peerId,
        public readonly float $score,
        public readonly int $consecutiveExclusions,
        public readonly ?DateTimeImmutable $excludedUntil,
        public readonly ?DateTimeImmutable $lastEventAt,
        public readonly int $totalEvents,
    ) {}

    public static function neutral(string $peerId, float $neutralScore = 100.0): self
    {
        return new self($peerId, $neutralScore, 0, null, null, 0);
    }

    public function isExcluded(DateTimeImmutable $now): bool
    {
        return $this->excludedUntil !== null && $this->excludedUntil > $now;
    }

    // Starts a fresh decision from the probation score once a cooldown has elapsed, keeping the
    // exclusion streak so repeated relapses still lengthen the next cooldown.
    public function onProbation(float $probationScore): self
    {
        return new self($this->peerId, $probationScore, $this->consecutiveExclusions, null, $this->lastEventAt, $this->totalEvents);
    }

    public function withExclusion(DateTimeImmutable $excludedUntil, int $consecutiveExclusions): self
    {
        return new self($this->peerId, $this->score, $consecutiveExclusions, $excludedUntil, $this->lastEventAt, $this->totalEvents);
    }

    public function withExclusionCleared(): self
    {
        return new self($this->peerId, $this->score, 0, null, $this->lastEventAt, $this->totalEvents);
    }

    public function withScore(float $score, DateTimeImmutable $now): self
    {
        return new self($this->peerId, $score, $this->consecutiveExclusions, $this->excludedUntil, $now, $this->totalEvents + 1);
    }
}

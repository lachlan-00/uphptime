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

use Uphptime\Storage\PeerRepositoryInterface;
use Uphptime\Storage\PeerReputationRepositoryInterface;
use Uphptime\Support\ClockInterface;
use Uphptime\Support\RandomInterface;

/**
 * A peer excluded by reputation is never picked, which is also how a node with every peer currently
 * circuit-broken falls back to a self-check exactly like one with no peers configured at all.
 */
final class PeerSelector implements PeerSelectorInterface
{
    public function __construct(
        private readonly PeerRepositoryInterface $peerRepository,
        private readonly PeerReputationRepositoryInterface $reputationRepository,
        private readonly PeerReputationScorer $scorer,
        private readonly ClockInterface $clock,
        private readonly RandomInterface $random,
    ) {}

    public function pick(array $excludePeerIds = []): ?Peer
    {
        $now = $this->clock->now();

        $candidates = array_values(array_filter(
            $this->peerRepository->enabledForDelegation(),
            function (Peer $peer) use ($excludePeerIds, $now): bool {
                if (in_array($peer->id, $excludePeerIds, true)) {
                    return false;
                }

                return !$this->scorer->isExcluded($this->reputationRepository->get($peer->id), $now);
            },
        ));

        if ($candidates === []) {
            return null;
        }

        return $candidates[$this->random->pickIndex(count($candidates))];
    }
}

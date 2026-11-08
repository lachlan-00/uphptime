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

namespace Uphptime\Storage;

use Uphptime\Peer\PeerReputation;

/**
 * Local-only reputation scores, one row per peer id, separate from the trust list itself: reputation
 * is written on every check cycle while peers.json is written only on admin edits.
 */
interface PeerReputationRepositoryInterface
{
    /** @return PeerReputation[] */
    public function all(): array;

    // Never null - an unscored peer is a fresh, neutral record, not a missing one.
    public function get(string $peerId): PeerReputation;

    public function remove(string $peerId): void;

    public function save(PeerReputation $reputation): void;
}

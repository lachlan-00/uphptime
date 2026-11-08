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

use Uphptime\Peer\Peer;
use Uphptime\Peer\PeerKey;

/**
 * Peers plus their keys, in the same storage bucket as sites: both are operational trust/state data
 * rather than static node settings.
 */
interface PeerRepositoryInterface
{
    /** @return Peer[] */
    public function all(): array;

    // Every peer this node may delegate checks to by config (enabled, allows outbound, has a base URL
    // and an active key) - reputation-based exclusion is a separate, selection-time concern.
    /** @return Peer[] */
    public function enabledForDelegation(): array;

    public function find(string $id): ?Peer;

    /** @return array{peer: Peer, key: PeerKey}|null */
    public function findByInboundKeyId(string $keyId): ?array;

    public function remove(string $id): void;

    public function save(Peer $peer): void;
}

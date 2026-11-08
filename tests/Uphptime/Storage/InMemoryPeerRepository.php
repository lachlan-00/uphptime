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

namespace Uphptime\Tests\Storage;

use Uphptime\Peer\Peer;
use Uphptime\Storage\PeerRepositoryInterface;

/**
 * An in-memory PeerRepositoryInterface double for tests that don't need real JSON/flock behavior.
 */
final class InMemoryPeerRepository implements PeerRepositoryInterface
{
    /** @var array<string, Peer> */
    private array $peers = [];

    public function all(): array
    {
        return array_values($this->peers);
    }

    public function enabledForDelegation(): array
    {
        return array_values(array_filter(
            $this->peers,
            static fn(Peer $peer): bool => $peer->enabled && $peer->allowOutboundDelegation && $peer->baseUrl !== null && $peer->activeOutboundKeyId() !== null,
        ));
    }

    public function find(string $id): ?Peer
    {
        return $this->peers[$id] ?? null;
    }

    public function findByInboundKeyId(string $keyId): ?array
    {
        foreach ($this->peers as $peer) {
            $key = $peer->findInboundKey($keyId);
            if ($key !== null) {
                return ['peer' => $peer, 'key' => $key];
            }
        }

        return null;
    }

    public function remove(string $id): void
    {
        unset($this->peers[$id]);
    }

    public function save(Peer $peer): void
    {
        $this->peers[$peer->id] = $peer;
    }
}

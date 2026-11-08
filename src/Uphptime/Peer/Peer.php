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

/**
 * A trusted peer: pairwise keys plus the local (never-transmitted) policy for dealing with it.
 */
final class Peer
{
    /** @param PeerKey[] $keys
     * @param string[] $targetAllowlist
     */
    public function __construct(
        public readonly string $id,
        public readonly string $label,
        public readonly ?string $baseUrl,
        public readonly bool $enabled,
        public readonly bool $allowOutboundDelegation,
        public readonly ?int $rateLimitPerMinute,
        public readonly array $targetAllowlist,
        public readonly array $keys,
    ) {}

    // The key ID this node currently signs outbound requests to this peer with, if any.
    public function activeOutboundKeyId(): ?string
    {
        return $this->outboundKey()?->keyId;
    }

    // The enabled, inbound-accepted key matching $keyId, used to verify a request claiming it.
    public function findInboundKey(string $keyId): ?PeerKey
    {
        foreach ($this->keys as $key) {
            if ($key->enabled && $key->inbound && $key->keyId === $keyId) {
                return $key;
            }
        }

        return null;
    }

    // The key this node currently signs outbound requests to this peer with, if any.
    public function outboundKey(): ?PeerKey
    {
        foreach ($this->keys as $key) {
            if ($key->enabled && $key->outbound) {
                return $key;
            }
        }

        return null;
    }
}

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

namespace Uphptime\Cli\Commands;

use Uphptime\Cli\ArgvOptions;
use Uphptime\Cli\CommandInterface;
use Uphptime\Cli\ConsoleOutputInterface;
use Uphptime\Peer\Peer;
use Uphptime\Peer\PeerKey;
use Uphptime\Storage\PeerRepositoryInterface;

/**
 * Removes a key entirely - the manual local-revocation path for a compromised key. A disabled-but-
 * present key would still be readable on disk and still show in peers:list, so retirement means gone,
 * not flagged. Does not refuse removing a peer's last remaining key; an emergency revocation that
 * temporarily breaks the relationship is exactly what this exists for.
 */
final class PeersKeysRetireCommand implements CommandInterface
{
    public function __construct(
        private readonly PeerRepositoryInterface $peerRepository,
        private readonly ConsoleOutputInterface $output,
    ) {}

    public function run(array $args): int
    {
        $options = ArgvOptions::parse($args);
        $peerId  = $options['peer'] ?? null;
        $keyId   = $options['key-id'] ?? null;

        if ($peerId === null || $keyId === null) {
            $this->output->writeErrorLine(json_encode(['error' => 'Missing required --peer and --key-id options.'], JSON_THROW_ON_ERROR));

            return 1;
        }

        $peer = $this->peerRepository->find($peerId);
        if ($peer === null) {
            $this->output->writeErrorLine(json_encode(['error' => sprintf('No peer with id "%s".', $peerId)], JSON_THROW_ON_ERROR));

            return 1;
        }

        $remaining = array_values(array_filter($peer->keys, static fn(PeerKey $key): bool => $key->keyId !== $keyId));
        if (count($remaining) === count($peer->keys)) {
            $this->output->writeErrorLine(json_encode(['error' => sprintf('Peer "%s" has no key "%s".', $peerId, $keyId)], JSON_THROW_ON_ERROR));

            return 1;
        }

        $this->peerRepository->save(new Peer(
            id: $peer->id,
            label: $peer->label,
            baseUrl: $peer->baseUrl,
            enabled: $peer->enabled,
            allowOutboundDelegation: $peer->allowOutboundDelegation,
            rateLimitPerMinute: $peer->rateLimitPerMinute,
            targetAllowlist: $peer->targetAllowlist,
            keys: $remaining,
        ));

        $this->output->writeLine(json_encode(['peer' => $peerId, 'retired_key_id' => $keyId], JSON_THROW_ON_ERROR));

        return 0;
    }
}

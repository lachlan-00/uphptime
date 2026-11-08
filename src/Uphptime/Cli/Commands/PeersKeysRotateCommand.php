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

use InvalidArgumentException;
use Uphptime\Cli\ArgvOptions;
use Uphptime\Cli\CommandInterface;
use Uphptime\Cli\ConsoleOutputInterface;
use Uphptime\Cli\PeerKeyOptionResolver;
use Uphptime\Peer\Peer;
use Uphptime\Peer\PeerKey;
use Uphptime\Storage\PeerRepositoryInterface;
use ValueError;

/**
 * Adds a new key as the active outbound one and demotes any previous active outbound key to
 * inbound-only (never removed) - no hard cutover: this node accepts the peer's still-old-signed
 * traffic while originating with the new key, until the peer rotates too and retires the old one.
 */
final class PeersKeysRotateCommand implements CommandInterface
{
    public function __construct(
        private readonly PeerRepositoryInterface $peerRepository,
        private readonly PeerKeyOptionResolver $keyResolver,
        private readonly ConsoleOutputInterface $output,
    ) {}

    public function run(array $args): int
    {
        $options = ArgvOptions::parse($args);
        $peerId  = $options['peer'] ?? null;

        if ($peerId === null) {
            $this->output->writeErrorLine(json_encode(['error' => 'Missing required --peer option.'], JSON_THROW_ON_ERROR));

            return 1;
        }

        $peer = $this->peerRepository->find($peerId);
        if ($peer === null) {
            $this->output->writeErrorLine(json_encode(['error' => sprintf('No peer with id "%s".', $peerId)], JSON_THROW_ON_ERROR));

            return 1;
        }

        try {
            $resolved = $this->keyResolver->resolve([...$options, 'key-direction' => 'both']);
        } catch (InvalidArgumentException|ValueError $exception) {
            $this->output->writeErrorLine(json_encode(['error' => $exception->getMessage()], JSON_THROW_ON_ERROR));

            return 1;
        }

        $oldOutbound = $peer->outboundKey();
        $keys        = array_map(
            static fn(PeerKey $key): PeerKey => $oldOutbound !== null && $key->keyId === $oldOutbound->keyId
                ? new PeerKey($key->keyId, $key->secret, false, true, $key->enabled, $key->createdAt)
                : $key,
            $peer->keys,
        );

        $updated = new Peer(
            id: $peer->id,
            label: $peer->label,
            baseUrl: $peer->baseUrl,
            enabled: $peer->enabled,
            allowOutboundDelegation: $peer->allowOutboundDelegation,
            rateLimitPerMinute: $peer->rateLimitPerMinute,
            targetAllowlist: $peer->targetAllowlist,
            keys: [...$keys, $resolved['key']],
        );

        $this->peerRepository->save($updated);

        $this->output->writeLine(json_encode([
            'peer' => $peer->id,
            'old_key_id' => $oldOutbound?->keyId,
            'new_key_id' => $resolved['key']->keyId,
            'secret_to_relay' => $resolved['generatedSecretBase64'],
        ], JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT));

        return 0;
    }
}

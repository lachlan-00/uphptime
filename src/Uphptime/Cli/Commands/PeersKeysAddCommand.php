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
use Uphptime\Cli\PeerKeyDirection;
use Uphptime\Cli\PeerKeyOptionResolver;
use Uphptime\Peer\Peer;
use Uphptime\Storage\PeerRepositoryInterface;
use ValueError;

/**
 * Adds one more key to an existing peer. Refuses a second active outbound key unless
 * --allow-multiple-outbound is passed, since Peer::outboundKey() only ever returns the first match -
 * peers:keys:rotate is the intended way to replace which key is actively used to sign.
 */
final class PeersKeysAddCommand implements CommandInterface
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
            $direction       = PeerKeyDirection::from($options['key-direction'] ?? 'both');
            [$wantsOutbound] = $direction->flags();
            if ($wantsOutbound && $peer->outboundKey() !== null && !isset($options['allow-multiple-outbound'])) {
                throw new InvalidArgumentException('An active outbound key already exists - use peers:keys:rotate, or pass --allow-multiple-outbound.');
            }

            $resolved = $this->keyResolver->resolve($options);
        } catch (InvalidArgumentException|ValueError $exception) {
            $this->output->writeErrorLine(json_encode(['error' => $exception->getMessage()], JSON_THROW_ON_ERROR));

            return 1;
        }

        $updated = new Peer(
            id: $peer->id,
            label: $peer->label,
            baseUrl: $peer->baseUrl,
            enabled: $peer->enabled,
            allowOutboundDelegation: $peer->allowOutboundDelegation,
            rateLimitPerMinute: $peer->rateLimitPerMinute,
            targetAllowlist: $peer->targetAllowlist,
            keys: [...$peer->keys, $resolved['key']],
        );

        $this->peerRepository->save($updated);

        $this->output->writeLine(json_encode([
            'peer' => $peer->id,
            'key_id' => $resolved['key']->keyId,
            'secret_to_relay' => $resolved['generatedSecretBase64'],
        ], JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT));

        return 0;
    }
}

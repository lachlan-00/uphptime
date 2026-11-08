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
use Uphptime\Storage\PeerRepositoryInterface;
use ValueError;

/**
 * Adds a peer with its first key, enabled and allowed to delegate by default; policy knobs (rate
 * limit, target allowlist) are adjusted afterward via peers:set/peers:allowlist:*.
 */
final class PeersAddCommand implements CommandInterface
{
    public function __construct(
        private readonly PeerRepositoryInterface $peerRepository,
        private readonly PeerKeyOptionResolver $keyResolver,
        private readonly ConsoleOutputInterface $output,
    ) {}

    public function run(array $args): int
    {
        $options = ArgvOptions::parse($args);

        try {
            $this->require($options, ['id', 'label']);
            if ($this->peerRepository->find($options['id']) !== null) {
                throw new InvalidArgumentException(sprintf('A peer with id "%s" already exists.', $options['id']));
            }

            $resolved = $this->keyResolver->resolve($options);
        } catch (InvalidArgumentException|ValueError $exception) {
            $this->output->writeErrorLine(json_encode(['error' => $exception->getMessage()], JSON_THROW_ON_ERROR));

            return 1;
        }

        $peer = new Peer(
            id: $options['id'],
            label: $options['label'],
            baseUrl: $options['base-url'] ?? null,
            enabled: true,
            allowOutboundDelegation: true,
            rateLimitPerMinute: isset($options['rate-limit']) ? (int) $options['rate-limit'] : null,
            targetAllowlist: [],
            keys: [$resolved['key']],
        );

        $this->peerRepository->save($peer);

        $this->output->writeLine(json_encode([
            'id' => $peer->id,
            'label' => $peer->label,
            'key_id' => $resolved['key']->keyId,
            'secret_to_relay' => $resolved['generatedSecretBase64'],
        ], JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT));

        return 0;
    }

    /**
     * @param array<string, string> $options
     * @param string[] $required
     */
    private function require(array $options, array $required): void
    {
        foreach ($required as $key) {
            if (!isset($options[$key])) {
                throw new InvalidArgumentException(sprintf('Missing required --%s option.', $key));
            }
        }
    }
}

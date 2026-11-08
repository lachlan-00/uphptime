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
use Uphptime\Peer\Peer;
use Uphptime\Storage\PeerRepositoryInterface;

/**
 * Updates only the settings actually passed on an existing peer; keys and the target allowlist are
 * always carried over untouched - those belong to the peers:keys and peers:allowlist commands.
 */
final class PeersSetCommand implements CommandInterface
{
    public function __construct(
        private readonly PeerRepositoryInterface $peerRepository,
        private readonly ConsoleOutputInterface $output,
    ) {}

    public function run(array $args): int
    {
        $options = ArgvOptions::parse($args);
        $id      = $options['id'] ?? null;

        if ($id === null) {
            $this->output->writeErrorLine(json_encode(['error' => 'Missing required --id option.'], JSON_THROW_ON_ERROR));

            return 1;
        }

        $peer = $this->peerRepository->find($id);
        if ($peer === null) {
            $this->output->writeErrorLine(json_encode(['error' => sprintf('No peer with id "%s".', $id)], JSON_THROW_ON_ERROR));

            return 1;
        }

        try {
            $this->assertNoConflicts($options);
            $updated = $this->applyOptions($peer, $options);
        } catch (InvalidArgumentException $exception) {
            $this->output->writeErrorLine(json_encode(['error' => $exception->getMessage()], JSON_THROW_ON_ERROR));

            return 1;
        }

        $this->peerRepository->save($updated);

        $this->output->writeLine(json_encode(['updated' => $updated->id], JSON_THROW_ON_ERROR));

        return 0;
    }

    /** @param array<string, string> $options */
    private function applyOptions(Peer $peer, array $options): Peer
    {
        $baseUrl = $peer->baseUrl;
        if (isset($options['base-url'])) {
            $baseUrl = $options['base-url'];
        } elseif (isset($options['no-base-url'])) {
            $baseUrl = null;
        }

        $rateLimit = $peer->rateLimitPerMinute;
        if (isset($options['rate-limit'])) {
            $rateLimit = (int) $options['rate-limit'];
        } elseif (isset($options['no-rate-limit'])) {
            $rateLimit = null;
        }

        $enabled = $peer->enabled;
        if (isset($options['enable'])) {
            $enabled = true;
        } elseif (isset($options['disable'])) {
            $enabled = false;
        }

        $allowOutbound = $peer->allowOutboundDelegation;
        if (isset($options['allow-outbound-delegation'])) {
            $allowOutbound = true;
        } elseif (isset($options['deny-outbound-delegation'])) {
            $allowOutbound = false;
        }

        return new Peer(
            id: $peer->id,
            label: $options['label'] ?? $peer->label,
            baseUrl: $baseUrl,
            enabled: $enabled,
            allowOutboundDelegation: $allowOutbound,
            rateLimitPerMinute: $rateLimit,
            targetAllowlist: $peer->targetAllowlist,
            keys: $peer->keys,
        );
    }

    /** @param array<string, string> $options */
    private function assertNoConflicts(array $options): void
    {
        $touchesAnySetting = array_key_exists('label', $options)
            || isset($options['base-url']) || isset($options['no-base-url'])
            || isset($options['rate-limit']) || isset($options['no-rate-limit'])
            || isset($options['enable']) || isset($options['disable'])
            || isset($options['allow-outbound-delegation']) || isset($options['deny-outbound-delegation']);

        if (!$touchesAnySetting) {
            throw new InvalidArgumentException('Nothing to update - pass at least one setting besides --id.');
        }

        $pairs = [
            ['base-url', 'no-base-url'],
            ['rate-limit', 'no-rate-limit'],
            ['enable', 'disable'],
            ['allow-outbound-delegation', 'deny-outbound-delegation'],
        ];

        foreach ($pairs as [$a, $b]) {
            if (isset($options[$a]) && isset($options[$b])) {
                throw new InvalidArgumentException(sprintf('--%s and --%s are mutually exclusive.', $a, $b));
            }
        }
    }
}

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
use Uphptime\Storage\PeerRepositoryInterface;

/**
 * Empties a peer's target allowlist back to the allow-all default.
 */
final class PeersAllowlistClearCommand implements CommandInterface
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

        $this->peerRepository->save(new Peer(
            id: $peer->id,
            label: $peer->label,
            baseUrl: $peer->baseUrl,
            enabled: $peer->enabled,
            allowOutboundDelegation: $peer->allowOutboundDelegation,
            rateLimitPerMinute: $peer->rateLimitPerMinute,
            targetAllowlist: [],
            keys: $peer->keys,
        ));

        $this->output->writeLine(json_encode(['id' => $id, 'target_allowlist' => []], JSON_THROW_ON_ERROR));

        return 0;
    }
}

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
use Uphptime\Config\Config;
use Uphptime\Peer\PeerReputation;
use Uphptime\Storage\PeerRepositoryInterface;
use Uphptime\Storage\PeerReputationRepositoryInterface;

/**
 * Clears a peer's reputation back to neutral - reputation is keyed by peer id, never by key id, so
 * rotating or retiring a key never touches it and an admin who resolved a dispute needs this directly.
 */
final class PeersReputationResetCommand implements CommandInterface
{
    public function __construct(
        private readonly PeerRepositoryInterface $peerRepository,
        private readonly PeerReputationRepositoryInterface $reputationRepository,
        private readonly Config $config,
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

        if ($this->peerRepository->find($id) === null) {
            $this->output->writeErrorLine(json_encode(['error' => sprintf('No peer with id "%s".', $id)], JSON_THROW_ON_ERROR));

            return 1;
        }

        $this->reputationRepository->save(PeerReputation::neutral($id, $this->config->peerReputationNeutralScore));

        $this->output->writeLine(json_encode(['id' => $id, 'reputation' => 'reset'], JSON_THROW_ON_ERROR));

        return 0;
    }
}

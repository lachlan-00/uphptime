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
use Uphptime\Storage\PeerRepositoryInterface;
use Uphptime\Storage\PeerReputationRepositoryInterface;

/**
 * Reputation is kept by default (an audit trail); --purge-reputation deletes it too. Reusing a
 * removed peer's id later without purging carries the old score/exclusion window forward.
 */
final class PeersRemoveCommand implements CommandInterface
{
    public function __construct(
        private readonly PeerRepositoryInterface $peerRepository,
        private readonly PeerReputationRepositoryInterface $reputationRepository,
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

        $this->peerRepository->remove($id);

        if (isset($options['purge-reputation'])) {
            $this->reputationRepository->remove($id);
        }

        $this->output->writeLine(json_encode(['removed' => $id], JSON_THROW_ON_ERROR));

        return 0;
    }
}

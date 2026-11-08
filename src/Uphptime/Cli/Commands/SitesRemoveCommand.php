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
use Uphptime\Storage\HistoryRepositoryInterface;
use Uphptime\Storage\RollupRepositoryInterface;
use Uphptime\Storage\SiteRepositoryInterface;

/**
 * History/rollup files are kept by default (audit trail); --purge-history deletes both for the site.
 */
final class SitesRemoveCommand implements CommandInterface
{
    public function __construct(
        private readonly SiteRepositoryInterface $siteRepository,
        private readonly HistoryRepositoryInterface $historyRepository,
        private readonly RollupRepositoryInterface $rollupRepository,
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

        if ($this->siteRepository->find($id) === null) {
            $this->output->writeErrorLine(json_encode(['error' => sprintf('No site with id "%s".', $id)], JSON_THROW_ON_ERROR));

            return 1;
        }

        $this->siteRepository->remove($id);

        if (isset($options['purge-history'])) {
            foreach ($this->historyRepository->datesWithHistory($id) as $date) {
                $this->historyRepository->deleteDay($id, $date);
            }

            foreach ($this->rollupRepository->datesWithRollup($id) as $date) {
                $this->rollupRepository->delete($id, $date);
            }
        }

        $this->output->writeLine(json_encode(['removed' => $id], JSON_THROW_ON_ERROR));

        return 0;
    }
}

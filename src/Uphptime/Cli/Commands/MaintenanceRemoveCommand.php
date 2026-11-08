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
use Uphptime\Site\MaintenanceWindow;
use Uphptime\Storage\SiteRepositoryInterface;

/**
 * Drops one maintenance window from a site by id. Not an error if it wasn't there, matching
 * PeersAllowlistRemoveCommand's idempotent-removal precedent.
 */
final class MaintenanceRemoveCommand implements CommandInterface
{
    public function __construct(
        private readonly SiteRepositoryInterface $siteRepository,
        private readonly ConsoleOutputInterface $output,
    ) {}

    public function run(array $args): int
    {
        $options  = ArgvOptions::parse($args);
        $siteId   = $options['site'] ?? null;
        $windowId = $options['window-id'] ?? null;

        if ($siteId === null || $windowId === null) {
            $this->output->writeErrorLine(json_encode(['error' => 'Missing required --site and --window-id options.'], JSON_THROW_ON_ERROR));

            return 1;
        }

        $site = $this->siteRepository->find($siteId);
        if ($site === null) {
            $this->output->writeErrorLine(json_encode(['error' => sprintf('No site with id "%s".', $siteId)], JSON_THROW_ON_ERROR));

            return 1;
        }

        $removed = false;
        $windows = [];
        foreach ($site->maintenanceWindows as $window) {
            if ($window->id === $windowId) {
                $removed = true;
                continue;
            }

            $windows[] = $window;
        }

        $this->siteRepository->save($site->withMaintenanceWindows($windows));

        $this->output->writeLine(json_encode([
            'site_id' => $siteId,
            'removed' => $removed,
            'windows' => array_map(static fn(MaintenanceWindow $window): string => $window->id, $windows),
        ], JSON_THROW_ON_ERROR));

        return 0;
    }
}

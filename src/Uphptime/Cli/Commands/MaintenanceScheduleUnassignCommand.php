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
use Uphptime\Storage\SiteRepositoryInterface;

/**
 * Un-assigns one catalog schedule from a site; the catalog entry itself is untouched.
 * Not an error if it wasn't assigned.
 */
final class MaintenanceScheduleUnassignCommand implements CommandInterface
{
    public function __construct(
        private readonly SiteRepositoryInterface $siteRepository,
        private readonly ConsoleOutputInterface $output,
    ) {}

    public function run(array $args): int
    {
        $options    = ArgvOptions::parse($args);
        $siteId     = $options['site'] ?? null;
        $scheduleId = $options['schedule-id'] ?? null;

        if ($siteId === null || $scheduleId === null) {
            $this->output->writeErrorLine(json_encode(['error' => 'Missing required --site and --schedule-id options.'], JSON_THROW_ON_ERROR));

            return 1;
        }

        $site = $this->siteRepository->find($siteId);
        if ($site === null) {
            $this->output->writeErrorLine(json_encode(['error' => sprintf('No site with id "%s".', $siteId)], JSON_THROW_ON_ERROR));

            return 1;
        }

        $removed     = in_array($scheduleId, $site->scheduleIds, true);
        $scheduleIds = array_values(array_filter($site->scheduleIds, static fn(string $id): bool => $id !== $scheduleId));

        $this->siteRepository->save($site->withScheduleIds($scheduleIds));

        $this->output->writeLine(json_encode([
            'site_id' => $siteId,
            'removed' => $removed,
            'schedule_ids' => $scheduleIds,
        ], JSON_THROW_ON_ERROR));

        return 0;
    }
}

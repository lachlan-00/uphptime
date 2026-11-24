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
use Uphptime\Storage\MaintenanceScheduleRepositoryInterface;
use Uphptime\Storage\SiteRepositoryInterface;

/**
 * Drops one schedule from the catalog by id and un-assigns it from every site that had it.
 * Not an error if it wasn't there.
 */
final class MaintenanceScheduleRemoveCommand implements CommandInterface
{
    public function __construct(
        private readonly MaintenanceScheduleRepositoryInterface $maintenanceScheduleRepository,
        private readonly SiteRepositoryInterface $siteRepository,
        private readonly ConsoleOutputInterface $output,
    ) {}

    public function run(array $args): int
    {
        $options    = ArgvOptions::parse($args);
        $scheduleId = $options['schedule-id'] ?? null;

        if ($scheduleId === null) {
            $this->output->writeErrorLine(json_encode(['error' => 'Missing required --schedule-id option.'], JSON_THROW_ON_ERROR));

            return 1;
        }

        $removed = $this->maintenanceScheduleRepository->find($scheduleId) instanceof \Uphptime\Site\MaintenanceSchedule;
        $this->maintenanceScheduleRepository->remove($scheduleId);

        $unassignedFrom = [];
        foreach ($this->siteRepository->all() as $site) {
            if (!in_array($scheduleId, $site->scheduleIds, true)) {
                continue;
            }

            $this->siteRepository->save($site->withScheduleIds(
                array_values(array_filter($site->scheduleIds, static fn(string $id): bool => $id !== $scheduleId)),
            ));
            $unassignedFrom[] = $site->id;
        }

        $this->output->writeLine(json_encode([
            'removed' => $removed,
            'unassigned_from_site_ids' => $unassignedFrom,
        ], JSON_THROW_ON_ERROR));

        return 0;
    }
}

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
use Uphptime\Site\MaintenanceSchedule;
use Uphptime\Site\Site;
use Uphptime\Storage\MaintenanceScheduleRepositoryInterface;
use Uphptime\Storage\SiteRepositoryInterface;

/**
 * Lists the standalone maintenance schedule catalog, with which sites each one is currently
 * assigned to. Pass --schedule-id to see just one, or --site to see only the schedules a
 * particular site is assigned to.
 */
final class MaintenanceScheduleListCommand implements CommandInterface
{
    public function __construct(
        private readonly MaintenanceScheduleRepositoryInterface $maintenanceScheduleRepository,
        private readonly SiteRepositoryInterface $siteRepository,
        private readonly ConsoleOutputInterface $output,
    ) {}

    public function run(array $args): int
    {
        $options   = ArgvOptions::parse($args);
        $scheduleId = $options['schedule-id'] ?? null;
        $siteId     = $options['site'] ?? null;

        $sites = $this->siteRepository->all();

        $schedules = $this->maintenanceScheduleRepository->all();
        if ($scheduleId !== null) {
            $schedules = array_values(array_filter($schedules, static fn(MaintenanceSchedule $schedule): bool => $schedule->id === $scheduleId));
        }

        if ($siteId !== null) {
            $site = $this->siteRepository->find($siteId);
            if ($site === null) {
                $this->output->writeErrorLine(json_encode(['error' => sprintf('No site with id "%s".', $siteId)], JSON_THROW_ON_ERROR));

                return 1;
            }

            $schedules = array_values(array_filter($schedules, static fn(MaintenanceSchedule $schedule): bool => in_array($schedule->id, $site->scheduleIds, true)));
        }

        $result = array_map(
            function (MaintenanceSchedule $schedule) use ($sites): array {
                return MaintenanceScheduleAddCommand::scheduleToArray($schedule) + [
                    'assigned_site_ids' => array_values(array_map(
                        static fn(Site $site): string => $site->id,
                        array_filter($sites, static fn(Site $site): bool => in_array($schedule->id, $site->scheduleIds, true)),
                    )),
                ];
            },
            $schedules,
        );

        $this->output->writeLine(json_encode(['schedules' => $result], JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT));

        return 0;
    }
}

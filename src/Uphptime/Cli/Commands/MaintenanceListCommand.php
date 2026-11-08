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

use DateTimeInterface;
use Uphptime\Cli\ArgvOptions;
use Uphptime\Cli\CommandInterface;
use Uphptime\Cli\ConsoleOutputInterface;
use Uphptime\Site\MaintenanceWindow;
use Uphptime\Storage\SiteRepositoryInterface;

/**
 * Lists one site's configured maintenance windows, past and future alike.
 */
final class MaintenanceListCommand implements CommandInterface
{
    public function __construct(
        private readonly SiteRepositoryInterface $siteRepository,
        private readonly ConsoleOutputInterface $output,
    ) {}

    public function run(array $args): int
    {
        $options = ArgvOptions::parse($args);
        $siteId  = $options['site'] ?? null;

        if ($siteId === null) {
            $this->output->writeErrorLine(json_encode(['error' => 'Missing required --site option.'], JSON_THROW_ON_ERROR));

            return 1;
        }

        $site = $this->siteRepository->find($siteId);
        if ($site === null) {
            $this->output->writeErrorLine(json_encode(['error' => sprintf('No site with id "%s".', $siteId)], JSON_THROW_ON_ERROR));

            return 1;
        }

        $windows = array_map(
            static fn(MaintenanceWindow $window): array => [
                'id' => $window->id,
                'starts_at' => $window->startsAt->format(DateTimeInterface::ATOM),
                'ends_at' => $window->endsAt->format(DateTimeInterface::ATOM),
                'note' => $window->note,
            ],
            $site->maintenanceWindows,
        );

        $this->output->writeLine(json_encode(['site_id' => $siteId, 'windows' => $windows], JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT));

        return 0;
    }
}

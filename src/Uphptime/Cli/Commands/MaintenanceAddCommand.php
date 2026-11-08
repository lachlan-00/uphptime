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

use DateTimeImmutable;
use DateTimeInterface;
use Exception;
use Uphptime\Cli\ArgvOptions;
use Uphptime\Cli\CommandInterface;
use Uphptime\Cli\ConsoleOutputInterface;
use Uphptime\Site\MaintenanceWindow;
use Uphptime\Storage\SiteRepositoryInterface;

/**
 * Appends a one-off [start, end) maintenance window to a site. Local bookkeeping only - never sent to
 * peers, who keep checking as normal; the window only decides whether the owner's own resulting
 * history records get marked `excluded_by_maintenance`.
 */
final class MaintenanceAddCommand implements CommandInterface
{
    public function __construct(
        private readonly SiteRepositoryInterface $siteRepository,
        private readonly ConsoleOutputInterface $output,
    ) {}

    public function run(array $args): int
    {
        $options = ArgvOptions::parse($args);
        $siteId  = $options['site'] ?? null;

        if ($siteId === null || !isset($options['start']) || !isset($options['end'])) {
            $this->output->writeErrorLine(json_encode(['error' => 'Missing required --site, --start and --end options.'], JSON_THROW_ON_ERROR));

            return 1;
        }

        $site = $this->siteRepository->find($siteId);
        if ($site === null) {
            $this->output->writeErrorLine(json_encode(['error' => sprintf('No site with id "%s".', $siteId)], JSON_THROW_ON_ERROR));

            return 1;
        }

        try {
            $startsAt = new DateTimeImmutable($options['start']);
            $endsAt   = new DateTimeImmutable($options['end']);
        } catch (Exception) {
            $this->output->writeErrorLine(json_encode(['error' => 'Unable to parse --start or --end as a date/time.'], JSON_THROW_ON_ERROR));

            return 1;
        }

        if ($endsAt <= $startsAt) {
            $this->output->writeErrorLine(json_encode(['error' => '--end must be after --start.'], JSON_THROW_ON_ERROR));

            return 1;
        }

        $window = new MaintenanceWindow(
            id: bin2hex(random_bytes(4)),
            startsAt: $startsAt,
            endsAt: $endsAt,
            note: $options['note'] ?? null,
        );

        $this->siteRepository->save($site->withMaintenanceWindows([...$site->maintenanceWindows, $window]));

        $this->output->writeLine(json_encode([
            'site_id' => $siteId,
            'window' => [
                'id' => $window->id,
                'starts_at' => $window->startsAt->format(DateTimeInterface::ATOM),
                'ends_at' => $window->endsAt->format(DateTimeInterface::ATOM),
                'note' => $window->note,
            ],
        ], JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT));

        return 0;
    }
}

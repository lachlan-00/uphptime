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
use Uphptime\Cli\CommandInterface;
use Uphptime\Cli\ConsoleOutputInterface;
use Uphptime\Config\Config;
use Uphptime\Site\Site;
use Uphptime\Storage\SiteRepositoryInterface;
use Uphptime\Support\ClockInterface;

/**
 * Lists sites with their staleness-aware effective state (see SiteRuntimeState::effectiveStatus()).
 */
final class SitesListCommand implements CommandInterface
{
    public function __construct(
        private readonly SiteRepositoryInterface $siteRepository,
        private readonly ClockInterface $clock,
        private readonly Config $config,
        private readonly ConsoleOutputInterface $output,
    ) {}

    public function run(array $args): int
    {
        $now   = $this->clock->now();
        $sites = array_map(
            fn(Site $site): array => [
                'id' => $site->id,
                'name' => $site->name,
                'url' => $site->url,
                'host' => $site->host,
                'port' => $site->port,
                'check_type' => $site->checkType->value,
                'enabled' => $site->enabled,
                'state' => $site->state->effectiveStatus($now, $this->config->staleAfterSeconds)->value,
                'in_maintenance' => $site->isUnderMaintenanceAt($now),
                'consecutive_fails' => $site->state->consecutiveFails,
                'last_checked_at' => $site->state->lastCheckedAt?->format(DateTimeInterface::ATOM),
                'next_due_at' => $site->state->nextDueAt->format(DateTimeInterface::ATOM),
            ],
            $this->siteRepository->all(),
        );

        $this->output->writeLine(json_encode(['sites' => $sites], JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT));

        return 0;
    }
}

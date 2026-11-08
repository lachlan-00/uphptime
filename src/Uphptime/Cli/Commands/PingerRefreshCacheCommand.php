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
use Uphptime\Pinger\CacheRefreshRunner;
use Uphptime\Scheduling\Lockfile;

/**
 * Exits 2 immediately (never blocks) if a previous run still holds the lock, mirroring CheckRunCommand.
 */
final class PingerRefreshCacheCommand implements CommandInterface
{
    public function __construct(
        private readonly CacheRefreshRunner $runner,
        private readonly Lockfile $lockfile,
        private readonly ConsoleOutputInterface $output,
    ) {}

    public function run(array $args): int
    {
        if (!$this->lockfile->acquire()) {
            $this->output->writeErrorLine(json_encode(['error' => 'Another pinger:refresh-cache is already in progress.'], JSON_THROW_ON_ERROR));

            return 2;
        }

        try {
            $options = ArgvOptions::parse($args);
            $budget  = isset($options['budget']) ? (int) $options['budget'] : null;
            $summary = $this->runner->run($budget);

            $this->output->writeLine(json_encode([
                'refreshed' => $summary->refreshed,
                'evicted' => $summary->evicted,
                'skipped_due_to_budget' => $summary->skippedDueToBudget,
                'duration_ms' => $summary->durationMs,
            ], JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT));

            return 0;
        } finally {
            $this->lockfile->release();
        }
    }
}

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

namespace Uphptime\Reporting;

use Uphptime\Storage\HistoryRepositoryInterface;
use Uphptime\Storage\RollupRepositoryInterface;
use Uphptime\Storage\SiteRepositoryInterface;
use Uphptime\Support\ClockInterface;

/**
 * Deletes raw history only once a rollup already covers it, so pruning never changes reported uptime.
 */
final class RetentionPruner
{
    public function __construct(
        private readonly SiteRepositoryInterface $siteRepository,
        private readonly HistoryRepositoryInterface $historyRepository,
        private readonly RollupRepositoryInterface $rollupRepository,
        private readonly ClockInterface $clock,
    ) {}

    // Returns how many raw history files were deleted.
    public function prune(int $retentionDays): int
    {
        $cutoff = $this->clock->now()->modify(sprintf('-%d days', $retentionDays))->format('Y-m-d');
        $pruned = 0;

        foreach ($this->siteRepository->all() as $site) {
            foreach ($this->historyRepository->datesWithHistory($site->id) as $date) {
                if ($date >= $cutoff || !$this->rollupRepository->exists($site->id, $date)) {
                    continue;
                }

                $this->historyRepository->deleteDay($site->id, $date);
                $pruned++;
            }
        }

        return $pruned;
    }
}

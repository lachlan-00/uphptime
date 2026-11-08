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

use Uphptime\Check\CheckOutcome;
use Uphptime\History\DailyRollup;
use Uphptime\History\HistoryRecord;
use Uphptime\History\HistoryRecordRole;
use Uphptime\Storage\HistoryRepositoryInterface;
use Uphptime\Storage\RollupRepositoryInterface;
use Uphptime\Support\ClockInterface;

/**
 * Computes day/month/overall uptime from closed rollups plus today's raw history.
 */
final class UptimeReportCalculator
{
    public function __construct(
        private readonly HistoryRepositoryInterface $historyRepository,
        private readonly RollupRepositoryInterface $rollupRepository,
        private readonly ClockInterface $clock,
    ) {}

    public function forDay(string $siteId, string $date): UptimeReport
    {
        $today = $this->clock->now()->format('Y-m-d');

        if ($date !== $today) {
            $rollup = $this->rollupRepository->read($siteId, $date);

            return $rollup !== null ? $this->fromRollups([$rollup]) : UptimeReport::empty();
        }

        return $this->fromRecords($this->historyRepository->readDay($siteId, $date));
    }

    public function forMonth(string $siteId, string $yearMonth): UptimeReport
    {
        $rollups = [];
        foreach ($this->rollupRepository->datesWithRollup($siteId) as $date) {
            if (str_starts_with($date, $yearMonth)) {
                $rollup = $this->rollupRepository->read($siteId, $date);
                if ($rollup !== null) {
                    $rollups[] = $rollup;
                }
            }
        }

        $today   = $this->clock->now()->format('Y-m-d');
        $records = str_starts_with($today, $yearMonth) ? $this->historyRepository->readDay($siteId, $today) : [];

        return $this->fromRollups($rollups)->merge($this->fromRecords($records));
    }

    // Since monitoring began: every closed rollup plus today's raw history.
    public function overall(string $siteId): UptimeReport
    {
        $rollups = [];
        foreach ($this->rollupRepository->datesWithRollup($siteId) as $date) {
            $rollup = $this->rollupRepository->read($siteId, $date);
            if ($rollup !== null) {
                $rollups[] = $rollup;
            }
        }

        $today = $this->clock->now()->format('Y-m-d');

        return $this->fromRollups($rollups)->merge($this->fromRecords($this->historyRepository->readDay($siteId, $today)));
    }

    /** @param HistoryRecord[] $records */
    private function fromRecords(array $records): UptimeReport
    {
        $pass        = 0;
        $unknown     = 0;
        $excluded    = 0;
        $selfSourced = 0;

        // Confirmation/canary/tie-break records are supplementary audit trail for the same cycle as
        // a primary record - only the primary outcome counts toward the uptime percentage.
        $records = array_values(array_filter($records, static fn(HistoryRecord $record): bool => $record->role === HistoryRecordRole::Primary));

        foreach ($records as $record) {
            if ($record->excludedByMaintenance) {
                $excluded++;
            } elseif ($record->outcome === CheckOutcome::Pass) {
                $pass++;
            } elseif ($record->outcome === CheckOutcome::Error) {
                $unknown++;
            }

            if ($record->source === 'self') {
                $selfSourced++;
            }
        }

        return new UptimeReport(count($records), $pass, $unknown, $excluded, $selfSourced);
    }

    /** @param DailyRollup[] $rollups */
    private function fromRollups(array $rollups): UptimeReport
    {
        $total       = 0;
        $pass        = 0;
        $unknown     = 0;
        $excluded    = 0;
        $selfSourced = 0;

        foreach ($rollups as $rollup) {
            $total += $rollup->checksTotal;
            $pass += $rollup->checksPass;
            $unknown += $rollup->checksUnknown;
            $excluded += $rollup->checksExcludedMaintenance;
            $selfSourced += $rollup->checksSelfSourced;
        }

        return new UptimeReport($total, $pass, $unknown, $excluded, $selfSourced);
    }
}

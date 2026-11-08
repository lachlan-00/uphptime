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
use Uphptime\Storage\SiteRepositoryInterface;
use Uphptime\Support\ClockInterface;

/**
 * Lazily closes any past day that has history but no rollup yet; idempotent, so no exact midnight tick is needed.
 */
final class RollupBuilder
{
    public function __construct(
        private readonly SiteRepositoryInterface $siteRepository,
        private readonly HistoryRepositoryInterface $historyRepository,
        private readonly RollupRepositoryInterface $rollupRepository,
        private readonly ClockInterface $clock,
    ) {}

    // Returns how many day-rollups were newly closed.
    public function closeFinishedDays(): int
    {
        $today  = $this->clock->now()->format('Y-m-d');
        $closed = 0;

        foreach ($this->siteRepository->all() as $site) {
            foreach ($this->historyRepository->datesWithHistory($site->id) as $date) {
                if ($date >= $today || $this->rollupRepository->exists($site->id, $date)) {
                    continue;
                }

                $this->rollupRepository->write($this->buildRollup($site->id, $date));
                $closed++;
            }
        }

        return $closed;
    }

    private function buildRollup(string $siteId, string $date): DailyRollup
    {
        // Confirmation/canary/tie-break records are supplementary audit trail for the same cycle as
        // a primary record - only the primary outcome counts toward a cycle's measured result.
        $records = array_values(array_filter(
            $this->historyRepository->readDay($siteId, $date),
            static fn(HistoryRecord $record): bool => $record->role === HistoryRecordRole::Primary,
        ));

        $pass         = 0;
        $fail         = 0;
        $unknown      = 0;
        $excluded     = 0;
        $selfSourced  = 0;
        $peerSourced  = 0;
        $firstCheckAt = null;
        $lastCheckAt  = null;

        foreach ($records as $record) {
            if ($record->excludedByMaintenance) {
                $excluded++;
            } elseif ($record->outcome === CheckOutcome::Pass) {
                $pass++;
            } elseif ($record->outcome === CheckOutcome::Fail) {
                $fail++;
            } else {
                $unknown++;
            }

            $record->source === 'self' ? $selfSourced++ : $peerSourced++;

            $firstCheckAt ??= $record->ts;
            $lastCheckAt = $record->ts;
        }

        $denominator   = count($records) - $unknown - $excluded;
        $uptimePercent = $denominator > 0 ? round(($pass / $denominator) * 100, 4) : null;

        return new DailyRollup(
            siteId: $siteId,
            date: $date,
            checksTotal: count($records),
            checksPass: $pass,
            checksFail: $fail,
            checksUnknown: $unknown,
            checksExcludedMaintenance: $excluded,
            checksSelfSourced: $selfSourced,
            checksPeerSourced: $peerSourced,
            uptimePercent: $uptimePercent,
            firstCheckAt: $firstCheckAt,
            lastCheckAt: $lastCheckAt,
            closedAt: $this->clock->now(),
        );
    }
}

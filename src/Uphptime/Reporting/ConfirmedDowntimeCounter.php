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
use Uphptime\History\HistoryRecord;
use Uphptime\History\HistoryRecordRole;

/**
 * Counts primary checks that belong to a run of two or more consecutive non-passes, so isolated missed checks are not downtime.
 */
final class ConfirmedDowntimeCounter
{
    public const MIN_CONSECUTIVE = 2;

    /** @param HistoryRecord[] $records every record for the period, any role, in any order */
    public function count(array $records): int
    {
        usort($records, static fn(HistoryRecord $a, HistoryRecord $b): int => $a->ts <=> $b->ts);

        $primaries = array_values(array_filter($records, static fn(HistoryRecord $record): bool => $record->role === HistoryRecordRole::Primary));
        $rechecks  = array_values(array_filter($records, static fn(HistoryRecord $record): bool => $record->role === HistoryRecordRole::Recheck && $record->outcome === CheckOutcome::Pass));

        $confirmed = 0;
        $run       = 0;

        foreach ($primaries as $index => $primary) {
            $next = $primaries[$index + 1] ?? null;

            if ($this->isDown($primary, $next, $rechecks)) {
                $run++;
                continue;
            }

            $confirmed += $this->confirmedInRun($run);
            $run = 0;
        }

        return $confirmed + $this->confirmedInRun($run);
    }

    private function confirmedInRun(int $run): int
    {
        return $run >= self::MIN_CONSECUTIVE ? $run : 0;
    }

    // A non-pass is recovered when a recheck passed after it and before the next primary check.
    /** @param HistoryRecord[] $passingRechecks */
    private function isDown(HistoryRecord $primary, ?HistoryRecord $next, array $passingRechecks): bool
    {
        if ($primary->excludedByMaintenance || $primary->outcome === CheckOutcome::Pass) {
            return false;
        }

        foreach ($passingRechecks as $recheck) {
            if ($recheck->ts > $primary->ts && (!$next instanceof HistoryRecord || $recheck->ts < $next->ts)) {
                return false;
            }
        }

        return true;
    }
}

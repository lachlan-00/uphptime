<?php

declare(strict_types=1);

/**
 * vim:set softtabstop=4 shiftwidth=4 expandtab:
 *
 * LICENSE: GNU Affero General Public License, version 3 (AGPL-3.0-or-later)
 * Copyright uPHPtime contributors, 2026
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

use DateTimeImmutable;
use Uphptime\Site\Site;

/**
 * How many checks a site should have produced for one day at its own interval - a full day in the
 * past, or only the elapsed fraction for today - to compare against checks_total in a day report.
 */
final class ExpectedChecksCalculator
{
    public static function forDay(Site $site, string $date, DateTimeImmutable $now, int $defaultIntervalSeconds): int
    {
        $interval = $site->intervalSeconds($defaultIntervalSeconds);
        $dayStart = new DateTimeImmutable($date . ' 00:00:00', $now->getTimezone());

        $elapsedSeconds = $date === $now->format('Y-m-d')
            ? max(0, $now->getTimestamp() - $dayStart->getTimestamp())
            : 86400;

        return intdiv($elapsedSeconds, $interval);
    }
}

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

namespace Uphptime\Site;

use DateTimeImmutable;
use DateTimeZone;

/**
 * A recurring "don't count this against uptime" window, evaluated in its own timezone. A standalone
 * catalog entry assignable to many sites via Site::$scheduleIds; local bookkeeping, never announced to peers.
 */
final class MaintenanceSchedule
{
    public function __construct(
        public readonly string $id,
        public readonly string $name,
        public readonly bool $enabled,
        // The first occurrence's start, interpreted in $timezone regardless of any offset it carries.
        public readonly DateTimeImmutable $startsAt,
        public readonly int $durationMinutes,
        // IANA name the schedule's start/occurrences are defined in, independent of the node's own timezone.
        public readonly string $timezone,
        public readonly int $repeatEvery,
        public readonly MaintenanceRepeatUnit $repeatUnit,
        // ISO-8601 day of week (1=Mon..7=Sun) for Week repeats; null defaults to startsAt's own weekday.
        public readonly ?int $repeatDayOfWeek,
        // Date (in $timezone) after which no further occurrences happen; null = repeats forever.
        public readonly ?DateTimeImmutable $endsOn,
        public readonly ?string $note,
    ) {}

    // Whether $when falls inside the current occurrence, evaluated in the schedule's own timezone.
    public function isActiveAt(DateTimeImmutable $when): bool
    {
        if (!$this->enabled) {
            return false;
        }

        $zone  = new DateTimeZone($this->timezone);
        $local = $when->setTimezone($zone);
        $start = $this->startsAt->setTimezone($zone);

        $occurrenceStart = $this->occurrenceStartAtOrBefore($start, $local);
        if ($occurrenceStart === null || $occurrenceStart < $start) {
            return false;
        }

        if ($this->endsOn !== null && $occurrenceStart > $this->endsOn) {
            return false;
        }

        $occurrenceEnd = $occurrenceStart->modify(sprintf('+%d minutes', $this->durationMinutes));

        return $local >= $occurrenceStart && $local < $occurrenceEnd;
    }

    private function monthlyOccurrenceStartAtOrBefore(DateTimeImmutable $start, DateTimeImmutable $local): ?DateTimeImmutable
    {
        $monthsElapsed = ((((int) $local->format('Y')) - (int) $start->format('Y')) * 12)
            + ((int) $local->format('n') - (int) $start->format('n'));
        $periodsAgo = intdiv($monthsElapsed, $this->repeatEvery);

        // Walks outward from the nearest guess, since calendar months vary in length.
        for ($attempt = $periodsAgo + 1; $attempt >= $periodsAgo - 1; $attempt--) {
            $candidate = $start->modify(sprintf('%+d months', $attempt * $this->repeatEvery));
            if ($candidate <= $local) {
                return $candidate;
            }
        }

        return null;
    }

    // The latest occurrence start at or before $local; Month uses calendar arithmetic, other units use timestamps.
    private function occurrenceStartAtOrBefore(DateTimeImmutable $start, DateTimeImmutable $local): ?DateTimeImmutable
    {
        if ($this->repeatUnit === MaintenanceRepeatUnit::Month) {
            return $this->monthlyOccurrenceStartAtOrBefore($start, $local);
        }

        $anchor = $this->repeatUnit === MaintenanceRepeatUnit::Week
            ? $this->shiftToDayOfWeek($start, $this->repeatDayOfWeek ?? (int) $start->format('N'))
            : $start;

        $unitSeconds   = match ($this->repeatUnit) {
            MaintenanceRepeatUnit::Hour => 3600,
            MaintenanceRepeatUnit::Day => 86400,
            default => 604800,
        };
        $periodSeconds = $this->repeatEvery * $unitSeconds;

        $elapsed    = $local->getTimestamp() - $anchor->getTimestamp();
        $periodsAgo = (int) floor($elapsed / $periodSeconds);

        return $anchor->modify(sprintf('%+d seconds', $periodsAgo * $periodSeconds));
    }

    private function shiftToDayOfWeek(DateTimeImmutable $when, int $dayOfWeek): DateTimeImmutable
    {
        return $when->modify(sprintf('%+d days', $dayOfWeek - (int) $when->format('N')));
    }
}

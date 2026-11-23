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

namespace Uphptime\Tests\Site;

use DateTimeImmutable;
use PHPUnit\Framework\TestCase;
use Uphptime\Site\MaintenanceRepeatUnit;
use Uphptime\Site\MaintenanceSchedule;

final class MaintenanceScheduleTest extends TestCase
{
    public function testActiveAfterMidnightLocalEvenThoughUtcDayHasRolledOver(): void
    {
        $schedule = $this->weeklySundayBrisbaneSchedule();

        // Mon 00:30 Brisbane (still the Sunday-night occurrence) is a Monday instant in UTC - the window follows its own timezone.
        self::assertTrue($schedule->isActiveAt(new DateTimeImmutable('2026-11-22T14:30:00+00:00')));
    }

    public function testActiveJustAfterLocalStart(): void
    {
        $schedule = $this->weeklySundayBrisbaneSchedule();

        // Sun 2026-11-22 23:55 Brisbane (UTC+10) = Sun 2026-11-22 13:55 UTC.
        self::assertTrue($schedule->isActiveAt(new DateTimeImmutable('2026-11-22T13:56:00+00:00')));
    }

    public function testDailyRepeat(): void
    {
        $schedule = new MaintenanceSchedule(
            id: 'sched-1',
            name: 'Weekly release',
            enabled: true,
            startsAt: new DateTimeImmutable('2026-11-01T02:00:00+00:00'),
            durationMinutes: 30,
            timezone: 'UTC',
            repeatEvery: 1,
            repeatUnit: MaintenanceRepeatUnit::Day,
            repeatDayOfWeek: null,
            endsOn: null,
            note: null,
        );

        self::assertTrue($schedule->isActiveAt(new DateTimeImmutable('2026-11-05T02:15:00+00:00')));
        self::assertFalse($schedule->isActiveAt(new DateTimeImmutable('2026-11-05T03:00:00+00:00')));
    }

    public function testDisabledScheduleIsNeverActive(): void
    {
        $schedule = new MaintenanceSchedule(
            id: 'sched-1',
            name: 'Weekly release',
            enabled: false,
            startsAt: new DateTimeImmutable('2026-11-01T23:55:00+10:00'),
            durationMinutes: 120,
            timezone: 'Australia/Brisbane',
            repeatEvery: 1,
            repeatUnit: MaintenanceRepeatUnit::Week,
            repeatDayOfWeek: null,
            endsOn: null,
            note: null,
        );

        self::assertFalse($schedule->isActiveAt(new DateTimeImmutable('2026-11-22T13:56:00+00:00')));
    }

    public function testEndsOnStopsFurtherOccurrences(): void
    {
        $schedule = $this->weeklySundayBrisbaneSchedule(new DateTimeImmutable('2026-11-23T00:00:00+10:00'));

        self::assertTrue($schedule->isActiveAt(new DateTimeImmutable('2026-11-22T13:56:00+00:00')));
        // The following week's occurrence starts after endsOn, so it no longer applies.
        self::assertFalse($schedule->isActiveAt(new DateTimeImmutable('2026-11-29T13:56:00+00:00')));
    }

    public function testExplicitRepeatDayOfWeekOverridesTheStartDate(): void
    {
        // startsAt is a Sunday, but the schedule should land on Tuesdays instead.
        $schedule = new MaintenanceSchedule(
            id: 'sched-1',
            name: 'Weekly release',
            enabled: true,
            startsAt: new DateTimeImmutable('2026-11-01T23:55:00+10:00'),
            durationMinutes: 120,
            timezone: 'Australia/Brisbane',
            repeatEvery: 1,
            repeatUnit: MaintenanceRepeatUnit::Week,
            repeatDayOfWeek: 2,
            endsOn: null,
            note: null,
        );

        // Tue 2026-11-24 23:55 Brisbane = Tue 2026-11-24 13:55 UTC.
        self::assertTrue($schedule->isActiveAt(new DateTimeImmutable('2026-11-24T13:56:00+00:00')));
        // The Sunday it previously would have landed on no longer applies.
        self::assertFalse($schedule->isActiveAt(new DateTimeImmutable('2026-11-22T13:56:00+00:00')));
    }

    public function testHourlyRepeat(): void
    {
        $schedule = new MaintenanceSchedule(
            id: 'sched-1',
            name: 'Weekly release',
            enabled: true,
            startsAt: new DateTimeImmutable('2026-11-01T00:00:00+00:00'),
            durationMinutes: 10,
            timezone: 'UTC',
            repeatEvery: 3,
            repeatUnit: MaintenanceRepeatUnit::Hour,
            repeatDayOfWeek: null,
            endsOn: null,
            note: null,
        );

        self::assertTrue($schedule->isActiveAt(new DateTimeImmutable('2026-11-01T09:05:00+00:00')));
        self::assertFalse($schedule->isActiveAt(new DateTimeImmutable('2026-11-01T07:05:00+00:00')));
    }

    public function testInactiveAfterWindowEnds(): void
    {
        $schedule = $this->weeklySundayBrisbaneSchedule();

        // Mon 2026-11-23 02:00 Brisbane, past the 2-hour window.
        self::assertFalse($schedule->isActiveAt(new DateTimeImmutable('2026-11-22T16:00:00+00:00')));
    }

    public function testInactiveBeforeFirstOccurrence(): void
    {
        $schedule = $this->weeklySundayBrisbaneSchedule();

        // A week before startsAt's own occurrence - the schedule shouldn't apply retroactively.
        self::assertFalse($schedule->isActiveAt(new DateTimeImmutable('2026-10-25T13:56:00+00:00')));
    }

    public function testInactiveBeforeWindowStarts(): void
    {
        $schedule = $this->weeklySundayBrisbaneSchedule();

        self::assertFalse($schedule->isActiveAt(new DateTimeImmutable('2026-11-22T13:00:00+00:00')));
    }

    public function testMonthlyRepeat(): void
    {
        $schedule = new MaintenanceSchedule(
            id: 'sched-1',
            name: 'Weekly release',
            enabled: true,
            startsAt: new DateTimeImmutable('2026-01-05T01:00:00+00:00'),
            durationMinutes: 60,
            timezone: 'UTC',
            repeatEvery: 1,
            repeatUnit: MaintenanceRepeatUnit::Month,
            repeatDayOfWeek: null,
            endsOn: null,
            note: null,
        );

        self::assertTrue($schedule->isActiveAt(new DateTimeImmutable('2026-04-05T01:30:00+00:00')));
        self::assertFalse($schedule->isActiveAt(new DateTimeImmutable('2026-04-06T01:30:00+00:00')));
    }

    public function testRepeatEveryTwoWeeksSkipsTheInBetweenWeek(): void
    {
        $schedule = new MaintenanceSchedule(
            id: 'sched-1',
            name: 'Weekly release',
            enabled: true,
            startsAt: new DateTimeImmutable('2026-11-01T23:55:00+10:00'),
            durationMinutes: 120,
            timezone: 'Australia/Brisbane',
            repeatEvery: 2,
            repeatUnit: MaintenanceRepeatUnit::Week,
            repeatDayOfWeek: null,
            endsOn: null,
            note: null,
        );

        // One week after start - should be skipped.
        self::assertFalse($schedule->isActiveAt(new DateTimeImmutable('2026-11-08T13:56:00+00:00')));
        // Two weeks after start - should land.
        self::assertTrue($schedule->isActiveAt(new DateTimeImmutable('2026-11-15T13:56:00+00:00')));
    }

    // Every week on Sunday 23:55 Brisbane (UTC+10, no DST) for 2 hours, checked against UTC instants.
    private function weeklySundayBrisbaneSchedule(?DateTimeImmutable $endsOn = null): MaintenanceSchedule
    {
        return new MaintenanceSchedule(
            id: 'sched-1',
            name: 'Weekly release',
            enabled: true,
            startsAt: new DateTimeImmutable('2026-11-01T23:55:00+10:00'),
            durationMinutes: 120,
            timezone: 'Australia/Brisbane',
            repeatEvery: 1,
            repeatUnit: MaintenanceRepeatUnit::Week,
            repeatDayOfWeek: null,
            endsOn: $endsOn,
            note: null,
        );
    }
}

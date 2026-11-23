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

namespace Uphptime\Tests\Cli\Commands;

use PHPUnit\Framework\TestCase;
use Uphptime\Cli\Commands\MaintenanceScheduleAddCommand;
use Uphptime\Site\MaintenanceRepeatUnit;
use Uphptime\Tests\Cli\FakeConsoleOutput;
use Uphptime\Tests\Storage\InMemoryMaintenanceScheduleRepository;

final class MaintenanceScheduleAddCommandTest extends TestCase
{
    public function testAddsAWeeklyScheduleToTheCatalog(): void
    {
        $schedules = new InMemoryMaintenanceScheduleRepository();

        $exitCode = (new MaintenanceScheduleAddCommand($schedules, new FakeConsoleOutput()))->run([
            '--name=Weekly release',
            '--start=2026-11-18 23:55',
            '--timezone=Australia/Brisbane',
            '--duration-minutes=120',
            '--repeat-every=1',
            '--repeat-unit=week',
            '--repeat-day-of-week=tuesday',
            '--note=Weekly release window',
        ]);

        self::assertSame(0, $exitCode);
        $all = $schedules->all();
        self::assertCount(1, $all);
        self::assertSame('Weekly release', $all[0]->name);
        self::assertSame(120, $all[0]->durationMinutes);
        self::assertSame('Australia/Brisbane', $all[0]->timezone);
        self::assertSame(1, $all[0]->repeatEvery);
        self::assertSame(MaintenanceRepeatUnit::Week, $all[0]->repeatUnit);
        self::assertSame(2, $all[0]->repeatDayOfWeek);
        self::assertTrue($all[0]->enabled);
        self::assertSame('Weekly release window', $all[0]->note);
    }

    public function testDefaultsToWeeklyRepeatOnTheStartDatesOwnDay(): void
    {
        $schedules = new InMemoryMaintenanceScheduleRepository();

        $exitCode = (new MaintenanceScheduleAddCommand($schedules, new FakeConsoleOutput()))->run([
            '--name=Weekly release',
            '--start=2026-11-18 23:55',
            '--timezone=Australia/Brisbane',
            '--duration-minutes=120',
        ]);

        self::assertSame(0, $exitCode);
        $all = $schedules->all();
        self::assertSame(MaintenanceRepeatUnit::Week, $all[0]->repeatUnit);
        self::assertSame(1, $all[0]->repeatEvery);
        self::assertNull($all[0]->repeatDayOfWeek);
    }

    public function testInvalidRepeatUnitFailsWithNonZeroExit(): void
    {
        $schedules = new InMemoryMaintenanceScheduleRepository();
        $command   = new MaintenanceScheduleAddCommand($schedules, new FakeConsoleOutput());

        $exitCode = $command->run([
            '--name=Weekly release',
            '--start=2026-11-18 23:55',
            '--timezone=UTC',
            '--duration-minutes=60',
            '--repeat-unit=fortnight',
        ]);

        self::assertSame(1, $exitCode);
        self::assertSame([], $schedules->all());
    }

    public function testMissingNameFailsWithNonZeroExit(): void
    {
        $schedules = new InMemoryMaintenanceScheduleRepository();
        $command   = new MaintenanceScheduleAddCommand($schedules, new FakeConsoleOutput());

        $exitCode = $command->run([
            '--start=2026-11-18 23:55',
            '--timezone=UTC',
            '--duration-minutes=60',
        ]);

        self::assertSame(1, $exitCode);
        self::assertSame([], $schedules->all());
    }

    public function testRepeatDayOfWeekWithNonWeeklyUnitFailsWithNonZeroExit(): void
    {
        $schedules = new InMemoryMaintenanceScheduleRepository();
        $command   = new MaintenanceScheduleAddCommand($schedules, new FakeConsoleOutput());

        $exitCode = $command->run([
            '--name=Weekly release',
            '--start=2026-11-18 23:55',
            '--timezone=UTC',
            '--duration-minutes=60',
            '--repeat-unit=day',
            '--repeat-day-of-week=monday',
        ]);

        self::assertSame(1, $exitCode);
        self::assertSame([], $schedules->all());
    }

    public function testUnknownTimezoneFailsWithNonZeroExit(): void
    {
        $schedules = new InMemoryMaintenanceScheduleRepository();
        $command   = new MaintenanceScheduleAddCommand($schedules, new FakeConsoleOutput());

        $exitCode = $command->run([
            '--name=Weekly release',
            '--start=2026-11-18 23:55',
            '--timezone=Not/ARealZone',
            '--duration-minutes=60',
        ]);

        self::assertSame(1, $exitCode);
        self::assertSame([], $schedules->all());
    }
}

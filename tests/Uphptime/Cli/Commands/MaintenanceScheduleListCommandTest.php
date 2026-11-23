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

use DateTimeImmutable;
use PHPUnit\Framework\TestCase;
use Uphptime\Cli\Commands\MaintenanceScheduleListCommand;
use Uphptime\Site\CheckType;
use Uphptime\Site\MaintenanceRepeatUnit;
use Uphptime\Site\MaintenanceSchedule;
use Uphptime\Site\Site;
use Uphptime\Site\SiteRuntimeState;
use Uphptime\Site\SiteState;
use Uphptime\Tests\Cli\FakeConsoleOutput;
use Uphptime\Tests\Storage\InMemoryMaintenanceScheduleRepository;
use Uphptime\Tests\Storage\InMemorySiteRepository;

final class MaintenanceScheduleListCommandTest extends TestCase
{
    public function testFiltersByScheduleId(): void
    {
        $schedules = new InMemoryMaintenanceScheduleRepository();
        $sites     = new InMemorySiteRepository();

        $schedules->save($this->schedule('sched-1'));
        $schedules->save($this->schedule('sched-2'));

        $output   = new FakeConsoleOutput();
        $exitCode = (new MaintenanceScheduleListCommand($schedules, $sites, $output))->run(['--schedule-id=sched-2']);

        self::assertSame(0, $exitCode);
        $decoded = $output->lastLineDecoded();
        self::assertCount(1, $decoded['schedules']);
        self::assertSame('sched-2', $decoded['schedules'][0]['id']);
    }

    public function testListsCatalogSchedulesWithTheirAssignedSites(): void
    {
        $schedules = new InMemoryMaintenanceScheduleRepository();
        $sites     = new InMemorySiteRepository();
        $now       = new DateTimeImmutable('2026-01-01T00:00:00+00:00');

        $schedule = new MaintenanceSchedule(
            id: 'sched-1',
            name: 'Weekly release',
            enabled: true,
            startsAt: new DateTimeImmutable('2026-11-01T23:55:00+10:00'),
            durationMinutes: 120,
            timezone: 'Australia/Brisbane',
            repeatEvery: 1,
            repeatUnit: MaintenanceRepeatUnit::Week,
            repeatDayOfWeek: 7,
            endsOn: null,
            note: null,
        );
        $schedules->save($schedule);

        $sites->save(new Site(
            id: 'site-a',
            name: 'Example',
            url: 'https://example.test/',
            host: null,
            port: null,
            checkType: CheckType::Http,
            keyword: null,
            keywordMode: null,
            checkIntervalSeconds: null,
            enabled: true,
            createdAt: $now,
            state: new SiteRuntimeState(SiteState::Unknown, 0, null, null, $now, null),
            scheduleIds: ['sched-1'],
        ));

        $output   = new FakeConsoleOutput();
        $exitCode = (new MaintenanceScheduleListCommand($schedules, $sites, $output))->run([]);

        self::assertSame(0, $exitCode);
        $decoded = $output->lastLineDecoded();
        self::assertCount(1, $decoded['schedules']);
        self::assertSame('sched-1', $decoded['schedules'][0]['id']);
        self::assertSame('week', $decoded['schedules'][0]['repeat_unit']);
        self::assertSame(7, $decoded['schedules'][0]['repeat_day_of_week']);
        self::assertSame(['site-a'], $decoded['schedules'][0]['assigned_site_ids']);
    }

    public function testUnknownSiteFailsWithNonZeroExit(): void
    {
        $command = new MaintenanceScheduleListCommand(new InMemoryMaintenanceScheduleRepository(), new InMemorySiteRepository(), new FakeConsoleOutput());

        $exitCode = $command->run(['--site=missing']);

        self::assertSame(1, $exitCode);
    }

    private function schedule(string $id): MaintenanceSchedule
    {
        return new MaintenanceSchedule(
            id: $id,
            name: 'Weekly release',
            enabled: true,
            startsAt: new DateTimeImmutable('2026-11-01T23:55:00+10:00'),
            durationMinutes: 120,
            timezone: 'Australia/Brisbane',
            repeatEvery: 1,
            repeatUnit: MaintenanceRepeatUnit::Week,
            repeatDayOfWeek: 7,
            endsOn: null,
            note: null,
        );
    }
}

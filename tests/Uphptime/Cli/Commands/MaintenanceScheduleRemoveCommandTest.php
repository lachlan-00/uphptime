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
use Uphptime\Cli\Commands\MaintenanceScheduleRemoveCommand;
use Uphptime\Site\CheckType;
use Uphptime\Site\MaintenanceRepeatUnit;
use Uphptime\Site\MaintenanceSchedule;
use Uphptime\Site\Site;
use Uphptime\Site\SiteRuntimeState;
use Uphptime\Site\SiteState;
use Uphptime\Tests\Cli\FakeConsoleOutput;
use Uphptime\Tests\Storage\InMemoryMaintenanceScheduleRepository;
use Uphptime\Tests\Storage\InMemorySiteRepository;

final class MaintenanceScheduleRemoveCommandTest extends TestCase
{
    public function testRemovesFromCatalogAndUnassignsFromEverySite(): void
    {
        [$schedules, $sites] = $this->catalogWithOneAssignedSchedule();

        $exitCode = (new MaintenanceScheduleRemoveCommand($schedules, $sites, new FakeConsoleOutput()))->run([
            '--schedule-id=sched-1',
        ]);

        self::assertSame(0, $exitCode);
        self::assertNull($schedules->find('sched-1'));
        self::assertSame([], $sites->find('site-a')?->scheduleIds);
    }

    public function testRemovingAMissingIdIsNotAnError(): void
    {
        [$schedules, $sites] = $this->catalogWithOneAssignedSchedule();

        $exitCode = (new MaintenanceScheduleRemoveCommand($schedules, $sites, new FakeConsoleOutput()))->run([
            '--schedule-id=does-not-exist',
        ]);

        self::assertSame(0, $exitCode);
        self::assertNotNull($schedules->find('sched-1'));
        self::assertSame(['sched-1'], $sites->find('site-a')?->scheduleIds);
    }

    public function testMissingScheduleIdFailsWithNonZeroExit(): void
    {
        $command = new MaintenanceScheduleRemoveCommand(new InMemoryMaintenanceScheduleRepository(), new InMemorySiteRepository(), new FakeConsoleOutput());

        $exitCode = $command->run([]);

        self::assertSame(1, $exitCode);
    }

    /** @return array{0: InMemoryMaintenanceScheduleRepository, 1: InMemorySiteRepository} */
    private function catalogWithOneAssignedSchedule(): array
    {
        $schedules = new InMemoryMaintenanceScheduleRepository();
        $sites     = new InMemorySiteRepository();
        $now       = new DateTimeImmutable('2026-01-01T00:00:00+00:00');

        $schedules->save(new MaintenanceSchedule(
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
        ));

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

        return [$schedules, $sites];
    }
}

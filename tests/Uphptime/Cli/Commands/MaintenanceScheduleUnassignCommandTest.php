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
use Uphptime\Cli\Commands\MaintenanceScheduleUnassignCommand;
use Uphptime\Site\CheckType;
use Uphptime\Site\Site;
use Uphptime\Site\SiteRuntimeState;
use Uphptime\Site\SiteState;
use Uphptime\Tests\Cli\FakeConsoleOutput;
use Uphptime\Tests\Storage\InMemorySiteRepository;

final class MaintenanceScheduleUnassignCommandTest extends TestCase
{
    public function testUnassignsFromASiteWithoutTouchingOtherAssignments(): void
    {
        $sites = $this->siteAssignedToTwoSchedules();

        $exitCode = (new MaintenanceScheduleUnassignCommand($sites, new FakeConsoleOutput()))->run([
            '--site=site-a',
            '--schedule-id=sched-1',
        ]);

        self::assertSame(0, $exitCode);
        self::assertSame(['sched-2'], $sites->find('site-a')?->scheduleIds);
    }

    public function testUnassigningAMissingIdIsNotAnError(): void
    {
        $sites = $this->siteAssignedToTwoSchedules();

        $exitCode = (new MaintenanceScheduleUnassignCommand($sites, new FakeConsoleOutput()))->run([
            '--site=site-a',
            '--schedule-id=does-not-exist',
        ]);

        self::assertSame(0, $exitCode);
        self::assertSame(['sched-1', 'sched-2'], $sites->find('site-a')?->scheduleIds);
    }

    public function testUnknownSiteFailsWithNonZeroExit(): void
    {
        $command = new MaintenanceScheduleUnassignCommand(new InMemorySiteRepository(), new FakeConsoleOutput());

        $exitCode = $command->run(['--site=missing', '--schedule-id=sched-1']);

        self::assertSame(1, $exitCode);
    }

    private function siteAssignedToTwoSchedules(): InMemorySiteRepository
    {
        $sites = new InMemorySiteRepository();
        $now   = new DateTimeImmutable('2026-01-01T00:00:00+00:00');

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
            scheduleIds: ['sched-1', 'sched-2'],
        ));

        return $sites;
    }
}

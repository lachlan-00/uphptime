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
use Uphptime\Cli\Commands\ReportCommand;
use Uphptime\Cli\Commands\SitesAddCommand;
use Uphptime\Config\Config;
use Uphptime\Reporting\SiteReportSetBuilder;
use Uphptime\Reporting\UptimeReportCalculator;
use Uphptime\Tests\Cli\FakeConsoleOutput;
use Uphptime\Tests\Storage\InMemoryHistoryRepository;
use Uphptime\Tests\Storage\InMemoryRollupRepository;
use Uphptime\Tests\Storage\InMemorySiteRepository;
use Uphptime\Tests\Support\FakeClock;

final class ReportCommandTest extends TestCase
{
    public function testDateAloneImpliesPeriodDayForAGroupReportToo(): void
    {
        $sites = new InMemorySiteRepository();
        $clock = new FakeClock('2026-11-01T00:00:00+00:00');
        (new SitesAddCommand($sites, $clock, new FakeConsoleOutput()))->run(['--url=https://example.test/', '--groups=prod']);

        $output  = new FakeConsoleOutput();
        $command = $this->command($sites, $clock, $output);

        $exitCode = $command->run(['--group=prod', '--date=2026-11-01']);

        self::assertSame(0, $exitCode);
        self::assertSame('day', $output->lastLineDecoded()['period']);
    }

    public function testGroupReportsEveryMatchingSitePlusAnOverall(): void
    {
        $sites = new InMemorySiteRepository();
        $clock = new FakeClock();
        $add   = new SitesAddCommand($sites, $clock, new FakeConsoleOutput());
        $add->run(['--url=https://a.test/', '--name=A', '--groups=prod']);
        $add->run(['--url=https://b.test/', '--name=B', '--groups=staging']);

        $output  = new FakeConsoleOutput();
        $command = $this->command($sites, $clock, $output);

        $exitCode = $command->run(['--group=prod']);

        self::assertSame(0, $exitCode);
        $decoded = $output->lastLineDecoded();
        self::assertSame('prod', $decoded['group']);
        self::assertCount(1, $decoded['items']);
        self::assertSame('A', $decoded['items'][0]['name']);
    }

    public function testHappyPathPrintsTheDocumentedJsonShape(): void
    {
        $sites = new InMemorySiteRepository();
        $clock = new FakeClock();
        (new SitesAddCommand($sites, $clock, new FakeConsoleOutput()))->run(['--url=https://example.test/']);
        $id = $sites->all()[0]->id;

        $output  = new FakeConsoleOutput();
        $command = $this->command($sites, $clock, $output);

        $exitCode = $command->run(['--id=' . $id, '--period=overall']);

        self::assertSame(0, $exitCode);
        $decoded = $output->lastLineDecoded();
        self::assertSame($id, $decoded['site_id']);
        self::assertSame('overall', $decoded['period']);
        self::assertArrayHasKey('uptime_percent', $decoded);
    }

    public function testMonthAloneImpliesPeriodMonthWithoutNeedingPeriodToo(): void
    {
        $sites = new InMemorySiteRepository();
        $clock = new FakeClock('2026-11-01T00:00:00+00:00');
        (new SitesAddCommand($sites, $clock, new FakeConsoleOutput()))->run(['--url=https://example.test/']);
        $id = $sites->all()[0]->id;

        $output  = new FakeConsoleOutput();
        $command = $this->command($sites, $clock, $output);

        $exitCode = $command->run(['--id=' . $id, '--month=2026-11']);

        self::assertSame(0, $exitCode);
        self::assertSame('month', $output->lastLineDecoded()['period']);
    }

    public function testMultipleSitesSharingANameRequireIdToDisambiguate(): void
    {
        $sites = new InMemorySiteRepository();
        $clock = new FakeClock();
        $add   = new SitesAddCommand($sites, $clock, new FakeConsoleOutput());
        $add->run(['--url=https://a.test/', '--name=Dup']);
        $add->run(['--url=https://b.test/', '--name=Dup']);

        $output  = new FakeConsoleOutput();
        $command = $this->command($sites, $clock, $output);

        self::assertSame(1, $command->run(['Dup']));
        self::assertNotNull($output->lastErrorLine());
    }

    public function testPositionalArgumentIsResolvedByIdOrName(): void
    {
        $sites = new InMemorySiteRepository();
        $clock = new FakeClock();
        (new SitesAddCommand($sites, $clock, new FakeConsoleOutput()))->run(['--url=https://example.test/', '--name=Example']);
        $id = $sites->all()[0]->id;

        $byId = $this->command($sites, $clock, new FakeConsoleOutput());
        self::assertSame(0, $byId->run([$id]));

        $byName = $this->command($sites, $clock, new FakeConsoleOutput());
        self::assertSame(0, $byName->run(['Example']));
    }

    public function testUnknownSiteIdFailsWithNonZeroExit(): void
    {
        $sites = new InMemorySiteRepository();
        $clock = new FakeClock();

        $command = $this->command($sites, $clock, new FakeConsoleOutput());

        self::assertSame(1, $command->run(['--id=missing']));
    }

    private function command(InMemorySiteRepository $sites, FakeClock $clock, FakeConsoleOutput $output): ReportCommand
    {
        $calculator = new UptimeReportCalculator(new InMemoryHistoryRepository(), new InMemoryRollupRepository(), $clock);
        $builder    = new SiteReportSetBuilder($sites, $calculator, $clock, new Config('data', 300, 90, 10, 240, 900, 'uphptime-test', 'UTC'));

        return new ReportCommand($sites, $calculator, $clock, $builder, $output);
    }
}

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
use Uphptime\Cli\Commands\SitesAddCommand;
use Uphptime\Tests\Cli\FakeConsoleOutput;
use Uphptime\Tests\Storage\InMemorySiteRepository;
use Uphptime\Tests\Support\FakeClock;

final class SitesAddCommandTest extends TestCase
{
    public function testGroupsOptionIsParsedFromACommaSeparatedList(): void
    {
        $sites   = new InMemorySiteRepository();
        $command = new SitesAddCommand($sites, new FakeClock(), new FakeConsoleOutput());

        $command->run(['--url=https://example.test/', '--name=Example', '--groups=prod, web']);

        self::assertSame(['prod', 'web'], $sites->all()[0]->groups);
    }

    public function testHappyPathPersistsAnUnknownSiteDueImmediately(): void
    {
        $sites   = new InMemorySiteRepository();
        $output  = new FakeConsoleOutput();
        $command = new SitesAddCommand($sites, new FakeClock(), $output);

        $exitCode = $command->run(['--url=https://example.test/', '--name=Example']);

        self::assertSame(0, $exitCode);
        self::assertCount(1, $sites->all());
        self::assertSame('Example', $sites->all()[0]->name);
        self::assertSame('Example', $output->lastLineDecoded()['name']);
    }

    public function testMissingRequiredOptionFailsWithNonZeroExit(): void
    {
        $sites   = new InMemorySiteRepository();
        $output  = new FakeConsoleOutput();
        $command = new SitesAddCommand($sites, new FakeClock(), $output);

        $exitCode = $command->run(['--type=tcp']);

        self::assertSame(1, $exitCode);
        self::assertCount(0, $sites->all());
        self::assertNotNull($output->lastErrorLine());
    }

    public function testUrlAndHostAreTrimmedOfStrayWhitespace(): void
    {
        $sites   = new InMemorySiteRepository();
        $command = new SitesAddCommand($sites, new FakeClock(), new FakeConsoleOutput());

        $command->run(["--url=https://example.test/\n", '--name=Example']);

        self::assertSame('https://example.test/', $sites->all()[0]->url);
    }
}

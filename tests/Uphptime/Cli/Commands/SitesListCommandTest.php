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
use Uphptime\Cli\Commands\SitesListCommand;
use Uphptime\Config\Config;
use Uphptime\Tests\Cli\FakeConsoleOutput;
use Uphptime\Tests\Storage\InMemorySiteRepository;
use Uphptime\Tests\Support\FakeClock;

final class SitesListCommandTest extends TestCase
{
    public function testListsPreviouslyAddedSites(): void
    {
        $sites = new InMemorySiteRepository();
        $clock = new FakeClock();
        (new SitesAddCommand($sites, $clock, new FakeConsoleOutput()))->run(['--url=https://example.test/']);

        $config = new Config('data', 300, 90, 10, 240, 900, 'uphptime-test', 'UTC');
        $output = new FakeConsoleOutput();

        $exitCode = (new SitesListCommand($sites, $clock, $config, $output))->run([]);

        self::assertSame(0, $exitCode);
        $decoded = $output->lastLineDecoded();
        self::assertCount(1, $decoded['sites']);
        self::assertSame('unknown', $decoded['sites'][0]['state']);
    }
}

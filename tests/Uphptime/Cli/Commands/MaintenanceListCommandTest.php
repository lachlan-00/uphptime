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
use Uphptime\Cli\Commands\MaintenanceAddCommand;
use Uphptime\Cli\Commands\MaintenanceListCommand;
use Uphptime\Site\CheckType;
use Uphptime\Site\Site;
use Uphptime\Site\SiteRuntimeState;
use Uphptime\Site\SiteState;
use Uphptime\Tests\Cli\FakeConsoleOutput;
use Uphptime\Tests\Storage\InMemorySiteRepository;

final class MaintenanceListCommandTest extends TestCase
{
    public function testListsWindowsAddedForTheSite(): void
    {
        $sites = $this->sitesWithOne();
        (new MaintenanceAddCommand($sites, new FakeConsoleOutput()))->run([
            '--site=site-a',
            '--start=2026-01-01T02:00:00+00:00',
            '--end=2026-01-01T04:00:00+00:00',
        ]);

        $output   = new FakeConsoleOutput();
        $exitCode = (new MaintenanceListCommand($sites, $output))->run(['--site=site-a']);

        self::assertSame(0, $exitCode);
        $decoded = $output->lastLineDecoded();
        self::assertSame('site-a', $decoded['site_id']);
        self::assertCount(1, $decoded['windows']);
    }

    public function testUnknownSiteFailsWithNonZeroExit(): void
    {
        $command = new MaintenanceListCommand(new InMemorySiteRepository(), new FakeConsoleOutput());

        self::assertSame(1, $command->run(['--site=missing']));
    }

    private function sitesWithOne(): InMemorySiteRepository
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
        ));

        return $sites;
    }
}

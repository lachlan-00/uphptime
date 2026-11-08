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
use Uphptime\Check\CheckOutcome;
use Uphptime\Cli\Commands\SitesAddCommand;
use Uphptime\Cli\Commands\SitesRemoveCommand;
use Uphptime\History\HistoryRecord;
use Uphptime\Site\CheckType;
use Uphptime\Site\SiteState;
use Uphptime\Tests\Cli\FakeConsoleOutput;
use Uphptime\Tests\Storage\InMemoryHistoryRepository;
use Uphptime\Tests\Storage\InMemoryRollupRepository;
use Uphptime\Tests\Storage\InMemorySiteRepository;
use Uphptime\Tests\Support\FakeClock;

final class SitesRemoveCommandTest extends TestCase
{
    public function testRemoveKeepsHistoryByDefault(): void
    {
        $sites = new InMemorySiteRepository();
        (new SitesAddCommand($sites, new FakeClock(), new FakeConsoleOutput()))->run(['--url=https://a.example.test/']);
        $id      = $sites->all()[0]->id;
        $history = $this->historyWithOneRecord($id);

        $command = new SitesRemoveCommand($sites, $history, new InMemoryRollupRepository(), new FakeConsoleOutput());
        self::assertSame(0, $command->run(['--id=' . $id]));
        self::assertNotEmpty($history->datesWithHistory($id), 'history is kept by default');
    }

    public function testRemoveWithPurgeFlagDeletesHistory(): void
    {
        $sites = new InMemorySiteRepository();
        (new SitesAddCommand($sites, new FakeClock(), new FakeConsoleOutput()))->run(['--url=https://b.example.test/']);
        $id      = $sites->all()[0]->id;
        $history = $this->historyWithOneRecord($id);

        $command = new SitesRemoveCommand($sites, $history, new InMemoryRollupRepository(), new FakeConsoleOutput());
        self::assertSame(0, $command->run(['--id=' . $id, '--purge-history']));
        self::assertEmpty($history->datesWithHistory($id), '--purge-history deletes the site\'s history');
    }

    public function testUnknownIdFailsWithNonZeroExit(): void
    {
        $sites   = new InMemorySiteRepository();
        $command = new SitesRemoveCommand($sites, new InMemoryHistoryRepository(), new InMemoryRollupRepository(), new FakeConsoleOutput());

        self::assertSame(1, $command->run(['--id=missing']));
    }

    private function historyWithOneRecord(string $id): InMemoryHistoryRepository
    {
        $history = new InMemoryHistoryRepository();
        $history->append($id, new HistoryRecord(
            new DateTimeImmutable('2026-01-01T00:00:00+00:00'),
            $id,
            CheckType::Http,
            'self',
            CheckOutcome::Pass,
            200,
            10,
            null,
            null,
            SiteState::Up,
            SiteState::Up,
            false,
        ));

        return $history;
    }
}

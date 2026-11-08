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

namespace Uphptime\Tests\Reporting;

use DateTimeImmutable;
use PHPUnit\Framework\TestCase;
use Uphptime\Check\CheckOutcome;
use Uphptime\History\DailyRollup;
use Uphptime\History\HistoryRecord;
use Uphptime\Reporting\RetentionPruner;
use Uphptime\Reporting\UptimeReportCalculator;
use Uphptime\Site\CheckType;
use Uphptime\Site\Site;
use Uphptime\Site\SiteRuntimeState;
use Uphptime\Site\SiteState;
use Uphptime\Tests\Storage\InMemoryHistoryRepository;
use Uphptime\Tests\Storage\InMemoryRollupRepository;
use Uphptime\Tests\Storage\InMemorySiteRepository;
use Uphptime\Tests\Support\FakeClock;

final class RetentionPrunerTest extends TestCase
{
    public function testOnlyDeletesOldDaysThatAlreadyHaveARollup(): void
    {
        $sites = new InMemorySiteRepository();
        $sites->save($this->site());
        $history = new InMemoryHistoryRepository();
        $rollups = new InMemoryRollupRepository();

        // Old, with a rollup: must be pruned.
        $history->append('site-1', $this->record('2025-01-01T00:00:00+00:00'));
        $rollups->write($this->rollup('2025-01-01'));

        // Old, without a rollup yet: must be kept.
        $history->append('site-1', $this->record('2025-01-02T00:00:00+00:00'));

        // Within the retention window: must be kept regardless of rollup state.
        $history->append('site-1', $this->record('2026-01-01T00:00:00+00:00'));
        $rollups->write($this->rollup('2026-01-01'));

        $clock  = new FakeClock('2026-01-02T00:00:00+00:00');
        $pruned = (new RetentionPruner($sites, $history, $rollups, $clock))->prune(90);

        self::assertSame(1, $pruned);
        self::assertSame(['2025-01-02', '2026-01-01'], $history->datesWithHistory('site-1'));
    }

    public function testPruningNeverChangesReportedUptime(): void
    {
        $sites = new InMemorySiteRepository();
        $sites->save($this->site());
        $history = new InMemoryHistoryRepository();
        $rollups = new InMemoryRollupRepository();

        $history->append('site-1', $this->record('2025-01-01T00:00:00+00:00'));
        $rollups->write($this->rollup('2025-01-01'));
        $history->append('site-1', $this->record('2026-01-01T00:00:00+00:00'));

        $clock      = new FakeClock('2026-01-02T00:00:00+00:00');
        $calculator = new UptimeReportCalculator($history, $rollups, $clock);

        $before = $calculator->overall('site-1');
        (new RetentionPruner($sites, $history, $rollups, $clock))->prune(90);
        $after = $calculator->overall('site-1');

        self::assertEquals($before->toArray(), $after->toArray());
    }

    private function record(string $date): HistoryRecord
    {
        return new HistoryRecord(
            new DateTimeImmutable($date),
            'site-1',
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
        );
    }

    private function rollup(string $date): DailyRollup
    {
        return new DailyRollup('site-1', $date, 1, 1, 0, 0, 0, 1, 0, 100.0, null, null, new DateTimeImmutable($date));
    }

    private function site(): Site
    {
        $now = new DateTimeImmutable('2026-01-01T00:00:00+00:00');

        return new Site(
            'site-1',
            'Example',
            'https://example.test/',
            null,
            null,
            CheckType::Http,
            null,
            null,
            null,
            true,
            $now,
            new SiteRuntimeState(SiteState::Up, 0, null, null, $now, null),
        );
    }
}

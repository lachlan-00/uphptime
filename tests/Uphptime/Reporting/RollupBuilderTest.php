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
use Uphptime\History\HistoryRecord;
use Uphptime\History\HistoryRecordRole;
use Uphptime\Reporting\RollupBuilder;
use Uphptime\Site\CheckType;
use Uphptime\Site\Site;
use Uphptime\Site\SiteRuntimeState;
use Uphptime\Site\SiteState;
use Uphptime\Tests\Storage\InMemoryHistoryRepository;
use Uphptime\Tests\Storage\InMemoryRollupRepository;
use Uphptime\Tests\Storage\InMemorySiteRepository;
use Uphptime\Tests\Support\FakeClock;

final class RollupBuilderTest extends TestCase
{
    public function testClosesAPastDayWithKnownCounts(): void
    {
        $sites = new InMemorySiteRepository();
        $sites->save($this->site('site-1'));
        $history = new InMemoryHistoryRepository();
        $history->append('site-1', $this->record('2026-01-01T00:00:00+00:00', CheckOutcome::Pass));
        $history->append('site-1', $this->record('2026-01-01T00:05:00+00:00', CheckOutcome::Pass));
        $history->append('site-1', $this->record('2026-01-01T00:10:00+00:00', CheckOutcome::Fail));
        $history->append('site-1', $this->record('2026-01-01T00:15:00+00:00', CheckOutcome::Error));
        $rollups = new InMemoryRollupRepository();
        $clock   = new FakeClock('2026-01-02T00:00:00+00:00');

        $closed = (new RollupBuilder($sites, $history, $rollups, $clock))->closeFinishedDays();

        self::assertSame(1, $closed);
        $rollup = $rollups->read('site-1', '2026-01-01');
        self::assertNotNull($rollup);
        self::assertSame(4, $rollup->checksTotal);
        self::assertSame(2, $rollup->checksPass);
        self::assertSame(1, $rollup->checksFail);
        self::assertSame(1, $rollup->checksUnknown);
        self::assertSame(66.6667, $rollup->uptimePercent);
    }

    public function testClosingTwiceIsANoOp(): void
    {
        $sites = new InMemorySiteRepository();
        $sites->save($this->site('site-1'));
        $history = new InMemoryHistoryRepository();
        $history->append('site-1', $this->record('2026-01-01T00:00:00+00:00', CheckOutcome::Pass));
        $rollups = new InMemoryRollupRepository();
        $clock   = new FakeClock('2026-01-02T00:00:00+00:00');
        $builder = new RollupBuilder($sites, $history, $rollups, $clock);

        self::assertSame(1, $builder->closeFinishedDays());
        self::assertSame(0, $builder->closeFinishedDays());
    }

    public function testConfirmationAndCanaryRecordsNeverCountTowardTheRollup(): void
    {
        $sites = new InMemorySiteRepository();
        $sites->save($this->site('site-1'));
        $history = new InMemoryHistoryRepository();
        $history->append('site-1', $this->record('2026-01-01T00:00:00+00:00', CheckOutcome::Fail));
        $history->append('site-1', $this->record('2026-01-01T00:00:01+00:00', CheckOutcome::Fail, role: HistoryRecordRole::Confirmation));
        $history->append('site-1', $this->record('2026-01-01T00:05:00+00:00', CheckOutcome::Pass));
        $history->append('site-1', $this->record('2026-01-01T00:05:01+00:00', CheckOutcome::Pass, role: HistoryRecordRole::Canary));
        $history->append('site-1', $this->record('2026-01-01T00:05:02+00:00', CheckOutcome::Pass, role: HistoryRecordRole::TieBreak));
        $rollups = new InMemoryRollupRepository();
        $clock   = new FakeClock('2026-01-02T00:00:00+00:00');

        (new RollupBuilder($sites, $history, $rollups, $clock))->closeFinishedDays();

        $rollup = $rollups->read('site-1', '2026-01-01');
        self::assertNotNull($rollup);
        self::assertSame(2, $rollup->checksTotal, 'only the two primary records count, not the three supplementary ones');
        self::assertSame(1, $rollup->checksPass);
        self::assertSame(1, $rollup->checksFail);
    }

    public function testTodayIsNeverClosed(): void
    {
        $sites = new InMemorySiteRepository();
        $sites->save($this->site('site-1'));
        $history = new InMemoryHistoryRepository();
        $history->append('site-1', $this->record('2026-01-02T00:00:00+00:00', CheckOutcome::Pass));
        $rollups = new InMemoryRollupRepository();
        $clock   = new FakeClock('2026-01-02T12:00:00+00:00');

        $closed = (new RollupBuilder($sites, $history, $rollups, $clock))->closeFinishedDays();

        self::assertSame(0, $closed);
        self::assertFalse($rollups->exists('site-1', '2026-01-02'));
    }

    private function record(string $date, CheckOutcome $outcome, bool $excluded = false, HistoryRecordRole $role = HistoryRecordRole::Primary): HistoryRecord
    {
        return new HistoryRecord(
            new DateTimeImmutable($date),
            'site-1',
            CheckType::Http,
            'self',
            $outcome,
            $outcome === CheckOutcome::Pass ? 200 : 500,
            10,
            null,
            null,
            SiteState::Up,
            SiteState::Up,
            $excluded,
            role: $role,
        );
    }

    private function site(string $id): Site
    {
        $now = new DateTimeImmutable('2026-01-01T00:00:00+00:00');

        return new Site(
            $id,
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

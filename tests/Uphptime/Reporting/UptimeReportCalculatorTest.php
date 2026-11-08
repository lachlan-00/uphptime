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
use Uphptime\History\HistoryRecordRole;
use Uphptime\Reporting\UptimeReportCalculator;
use Uphptime\Site\CheckType;
use Uphptime\Site\SiteState;
use Uphptime\Tests\Storage\InMemoryHistoryRepository;
use Uphptime\Tests\Storage\InMemoryRollupRepository;
use Uphptime\Tests\Support\FakeClock;

final class UptimeReportCalculatorTest extends TestCase
{
    public function testForDayUsesRollupWhenClosedAndRawHistoryForToday(): void
    {
        $history = new InMemoryHistoryRepository();
        $rollups = new InMemoryRollupRepository();
        $rollups->write(new DailyRollup('site-1', '2026-01-01', 5, 5, 0, 0, 0, 5, 0, 100.0, null, null, new DateTimeImmutable('2026-01-02')));
        $history->append('site-1', $this->record('2026-01-02T00:00:00+00:00', CheckOutcome::Fail));

        $calculator = new UptimeReportCalculator($history, $rollups, new FakeClock('2026-01-02T12:00:00+00:00'));

        self::assertSame(100.0, $calculator->forDay('site-1', '2026-01-01')->uptimePercent());
        self::assertSame(0.0, $calculator->forDay('site-1', '2026-01-02')->uptimePercent());
    }

    public function testNoDataYetReturnsNullPercentagesNotADivisionError(): void
    {
        $calculator = new UptimeReportCalculator(new InMemoryHistoryRepository(), new InMemoryRollupRepository(), new FakeClock());

        $report = $calculator->overall('site-1');

        self::assertSame(0, $report->checksTotal);
        self::assertNull($report->uptimePercent());
        self::assertNull($report->unknownPercent());
    }

    public function testOverallCombinesClosedRollupsWithTodaysRawHistory(): void
    {
        $history = new InMemoryHistoryRepository();
        $rollups = new InMemoryRollupRepository();
        $rollups->write(new DailyRollup('site-1', '2026-01-01', 10, 9, 1, 0, 0, 10, 0, 90.0, null, null, new DateTimeImmutable('2026-01-02')));
        $history->append('site-1', $this->record('2026-01-02T00:00:00+00:00', CheckOutcome::Pass));
        $history->append('site-1', $this->record('2026-01-02T00:05:00+00:00', CheckOutcome::Fail));

        $calculator = new UptimeReportCalculator($history, $rollups, new FakeClock('2026-01-02T12:00:00+00:00'));
        $report     = $calculator->overall('site-1');

        self::assertSame(12, $report->checksTotal);
        self::assertSame(10, $report->checksPass);
        self::assertEqualsWithDelta(83.3333, $report->uptimePercent(), 0.0001);
    }

    public function testTodaysConfirmationAndCanaryRecordsNeverCountTowardTheRawPortion(): void
    {
        $history = new InMemoryHistoryRepository();
        $history->append('site-1', $this->record('2026-01-02T00:00:00+00:00', CheckOutcome::Pass));
        $history->append('site-1', $this->record('2026-01-02T00:00:01+00:00', CheckOutcome::Fail, HistoryRecordRole::Canary));

        $calculator = new UptimeReportCalculator($history, new InMemoryRollupRepository(), new FakeClock('2026-01-02T12:00:00+00:00'));
        $report     = $calculator->forDay('site-1', '2026-01-02');

        self::assertSame(1, $report->checksTotal);
        self::assertSame(100.0, $report->uptimePercent());
    }

    private function record(string $date, CheckOutcome $outcome, HistoryRecordRole $role = HistoryRecordRole::Primary): HistoryRecord
    {
        return new HistoryRecord(
            new DateTimeImmutable($date),
            'site-1',
            CheckType::Http,
            'self',
            $outcome,
            200,
            10,
            null,
            null,
            SiteState::Up,
            SiteState::Up,
            false,
            role: $role,
        );
    }
}

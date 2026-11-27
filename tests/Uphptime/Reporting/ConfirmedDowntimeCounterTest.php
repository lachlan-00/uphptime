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
use Uphptime\Reporting\ConfirmedDowntimeCounter;
use Uphptime\Site\CheckType;
use Uphptime\Site\SiteState;

final class ConfirmedDowntimeCounterTest extends TestCase
{
    public function testFailingRecheckDoesNotRecoverAnything(): void
    {
        $records = [
            $this->record(0, CheckOutcome::Fail),
            $this->record(2, CheckOutcome::Fail, HistoryRecordRole::Recheck),
            $this->record(5, CheckOutcome::Fail),
        ];

        self::assertSame(2, (new ConfirmedDowntimeCounter())->count($records));
    }

    public function testIsolatedMissesAreNotDowntime(): void
    {
        $records = [
            $this->record(0, CheckOutcome::Fail),
            $this->record(5, CheckOutcome::Pass),
            $this->record(10, CheckOutcome::Error),
            $this->record(15, CheckOutcome::Pass),
            $this->record(20, CheckOutcome::Fail),
        ];

        self::assertSame(0, (new ConfirmedDowntimeCounter())->count($records));
    }

    public function testMaintenanceBreaksARun(): void
    {
        $records = [
            $this->record(0, CheckOutcome::Fail),
            $this->record(5, CheckOutcome::Fail, excluded: true),
            $this->record(10, CheckOutcome::Fail),
        ];

        self::assertSame(0, (new ConfirmedDowntimeCounter())->count($records));
    }

    public function testRecheckPassBetweenPrimariesRecoversTheEarlierMiss(): void
    {
        $records = [
            $this->record(0, CheckOutcome::Fail),
            $this->record(2, CheckOutcome::Pass, HistoryRecordRole::Recheck),
            $this->record(5, CheckOutcome::Fail),
            $this->record(10, CheckOutcome::Fail),
        ];

        self::assertSame(2, (new ConfirmedDowntimeCounter())->count($records));
    }

    public function testRecordsAreOrderedByTimeAndNonPrimaryRecordsAreNotCounted(): void
    {
        $records = [
            $this->record(10, CheckOutcome::Fail),
            $this->record(5, CheckOutcome::Fail),
            $this->record(7, CheckOutcome::Fail, HistoryRecordRole::Confirmation),
        ];

        self::assertSame(2, (new ConfirmedDowntimeCounter())->count($records));
    }

    public function testTwoOrMoreConsecutiveNonPassesCountEveryCheckInTheRun(): void
    {
        $records = [
            $this->record(0, CheckOutcome::Pass),
            $this->record(5, CheckOutcome::Fail),
            $this->record(10, CheckOutcome::Error),
            $this->record(15, CheckOutcome::Fail),
            $this->record(20, CheckOutcome::Pass),
            $this->record(25, CheckOutcome::Fail),
        ];

        self::assertSame(3, (new ConfirmedDowntimeCounter())->count($records));
    }

    private function record(int $minute, CheckOutcome $outcome, HistoryRecordRole $role = HistoryRecordRole::Primary, bool $excluded = false): HistoryRecord
    {
        return new HistoryRecord(
            (new DateTimeImmutable('2026-01-02T00:00:00+00:00'))->modify(sprintf('+%d minutes', $minute)),
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
            $excluded,
            role: $role,
        );
    }
}

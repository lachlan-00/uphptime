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

namespace Uphptime\Tests\Check;

use DateTimeImmutable;
use PHPUnit\Framework\TestCase;
use Uphptime\Check\CheckOutcome;
use Uphptime\Check\CheckResult;
use Uphptime\Check\SiteRechecker;
use Uphptime\Config\Config;
use Uphptime\History\HistoryRecordRole;
use Uphptime\Site\CheckType;
use Uphptime\Site\Site;
use Uphptime\Site\SiteRuntimeState;
use Uphptime\Site\SiteState;
use Uphptime\Site\SiteStateMachine;
use Uphptime\Tests\Storage\InMemoryHistoryRepository;
use Uphptime\Tests\Storage\InMemoryMaintenanceScheduleRepository;
use Uphptime\Tests\Storage\InMemorySiteRepository;
use Uphptime\Tests\Support\FakeClock;

final class SiteRecheckerTest extends TestCase
{
    public function testErroredSiteThatPassesIsRestored(): void
    {
        [$rechecker, $sites] = $this->build([CheckResult::pass()], [$this->site('a', SiteState::Up, CheckOutcome::Error)]);

        $rechecker->run(20);

        self::assertSame(CheckOutcome::Pass, $sites->all()[0]->state->lastOutcome);
    }

    public function testFailingRecheckIsRecordedWithoutChangingState(): void
    {
        [$rechecker, $sites, $history] = $this->build([CheckResult::fail('down')], [$this->site('a', SiteState::Suspect, CheckOutcome::Fail)]);

        $summary = $rechecker->run(20);

        self::assertSame(1, $summary->rechecked);
        self::assertSame(0, $summary->recovered);
        self::assertSame(SiteState::Suspect, $sites->all()[0]->state->status);
        self::assertCount(1, $history->readDay('a', '2026-01-02'));
    }

    public function testHealthyAndDisabledSitesAreNotRechecked(): void
    {
        $disabled                = $this->site('b', SiteState::Down, CheckOutcome::Fail, false);
        [$rechecker, , $history] = $this->build([], [$this->site('a', SiteState::Up, CheckOutcome::Pass), $disabled]);

        $summary = $rechecker->run(20);

        self::assertSame(0, $summary->candidates);
        self::assertSame([], $history->readDay('a', '2026-01-02'));
        self::assertSame([], $history->readDay('b', '2026-01-02'));
    }

    public function testPassingRecheckRestoresSuspectSiteAndKeepsSchedule(): void
    {
        [$rechecker, $sites, $history] = $this->build([CheckResult::pass()], [$this->site('a', SiteState::Suspect, CheckOutcome::Fail)]);

        $summary = $rechecker->run(20);

        self::assertSame(1, $summary->recovered);
        $saved = $sites->all()[0];
        self::assertSame(SiteState::Up, $saved->state->status);
        self::assertSame(0, $saved->state->consecutiveFails);
        self::assertEquals(new DateTimeImmutable('2026-01-02T00:05:00+00:00'), $saved->state->nextDueAt);

        $records = $history->readDay('a', '2026-01-02');
        self::assertCount(1, $records);
        self::assertSame(HistoryRecordRole::Recheck, $records[0]->role);
    }

    public function testZeroBudgetSkipsEverySite(): void
    {
        [$rechecker] = $this->build([], [$this->site('a', SiteState::Down, CheckOutcome::Fail)]);

        $summary = $rechecker->run(0);

        self::assertSame(1, $summary->skippedDueToBudget);
        self::assertSame(0, $summary->rechecked);
    }

    /**
     * @param CheckResult[] $results
     * @param Site[]        $sites
     * @return array{SiteRechecker, InMemorySiteRepository, InMemoryHistoryRepository}
     */
    private function build(array $results, array $sites): array
    {
        $repository = new InMemorySiteRepository();
        foreach ($sites as $site) {
            $repository->save($site);
        }

        $history = new InMemoryHistoryRepository();
        $config  = new Config('data', 300, 90, 10, 240, 900, 'uphptime-test', 'UTC');

        return [
            new SiteRechecker(
                $repository,
                $history,
                new InMemoryMaintenanceScheduleRepository(),
                new SiteStateMachine(),
                new FakeClock('2026-01-02T00:02:00+00:00'),
                $config,
                new FakeChecker($results),
                new FakeChecker(),
                new FakeChecker(),
                new FakeChecker(),
            ),
            $repository,
            $history,
        ];
    }

    private function site(string $id, SiteState $status, CheckOutcome $lastOutcome, bool $enabled = true): Site
    {
        $nextDueAt = new DateTimeImmutable('2026-01-02T00:05:00+00:00');

        return new Site(
            $id,
            $id,
            'https://example.test/',
            null,
            null,
            CheckType::Http,
            null,
            null,
            null,
            $enabled,
            $nextDueAt,
            new SiteRuntimeState($status, $status === SiteState::Up ? 0 : 1, $lastOutcome, new DateTimeImmutable('2026-01-02T00:00:00+00:00'), $nextDueAt, null),
        );
    }
}

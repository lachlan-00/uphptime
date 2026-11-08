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

namespace Uphptime\Tests\Site;

use DateTimeImmutable;
use PHPUnit\Framework\TestCase;
use Uphptime\Check\CheckOutcome;
use Uphptime\Site\SiteRuntimeState;
use Uphptime\Site\SiteState;
use Uphptime\Site\SiteStateMachine;

final class SiteStateMachineTest extends TestCase
{
    private SiteStateMachine $machine;
    private DateTimeImmutable $t0;
    private DateTimeImmutable $t1;

    /** @return array<string, array{SiteState, int, CheckOutcome, SiteState, int}> */
    public static function transitionProvider(): array
    {
        return [
            'unknown + pass -> up' => [SiteState::Unknown, 0, CheckOutcome::Pass, SiteState::Up, 0],
            'unknown + fail -> suspect' => [SiteState::Unknown, 0, CheckOutcome::Fail, SiteState::Suspect, 1],
            'up + pass -> up' => [SiteState::Up, 0, CheckOutcome::Pass, SiteState::Up, 0],
            'up + fail -> suspect' => [SiteState::Up, 0, CheckOutcome::Fail, SiteState::Suspect, 1],
            'suspect + fail -> down' => [SiteState::Suspect, 1, CheckOutcome::Fail, SiteState::Down, 2],
            'suspect + pass -> up' => [SiteState::Suspect, 1, CheckOutcome::Pass, SiteState::Up, 0],
            'down + fail -> down' => [SiteState::Down, 2, CheckOutcome::Fail, SiteState::Down, 3],
            'down + pass -> up' => [SiteState::Down, 5, CheckOutcome::Pass, SiteState::Up, 0],
        ];
    }

    /** @dataProvider transitionProvider */
    public function testErrorOutcomeNeverMutatesStateOrCounter(SiteState $before, int $failsBefore): void
    {
        $result = $this->machine->apply($this->state($before, $failsBefore), CheckOutcome::Error, $this->t1);

        self::assertSame($before, $result->status, 'a check-machinery error must not move the state');
        self::assertSame($failsBefore, $result->consecutiveFails, 'a check-machinery error must not touch the fail counter');
        self::assertSame(CheckOutcome::Error, $result->lastOutcome);
        self::assertSame($this->t1, $result->lastCheckedAt);
    }

    /** @dataProvider transitionProvider */
    public function testTransition(
        SiteState $before,
        int $failsBefore,
        CheckOutcome $outcome,
        SiteState $after,
        int $failsAfter,
    ): void {
        $result = $this->machine->apply($this->state($before, $failsBefore), $outcome, $this->t1);

        self::assertSame($after, $result->status);
        self::assertSame($failsAfter, $result->consecutiveFails);
        self::assertSame($outcome, $result->lastOutcome);
        self::assertSame($this->t1, $result->lastCheckedAt);
    }

    public function testTransitionTimestampOnlyUpdatesOnAnActualStatusChange(): void
    {
        $suspect = $this->machine->apply($this->state(SiteState::Up), CheckOutcome::Fail, $this->t1);
        self::assertSame($this->t1, $suspect->lastTransitionAt, 'up -> suspect is a status change');

        $downAt = $this->t1->modify('+5 minutes');
        $down   = $this->machine->apply($suspect, CheckOutcome::Fail, $downAt);
        self::assertSame($downAt, $down->lastTransitionAt, 'suspect -> down is also a status change');

        $stillDownAt = $downAt->modify('+5 minutes');
        $stillDown   = $this->machine->apply($down, CheckOutcome::Fail, $stillDownAt);
        self::assertSame($downAt, $stillDown->lastTransitionAt, 'down -> down is not a status change');
    }

    protected function setUp(): void
    {
        $this->machine = new SiteStateMachine();
        $this->t0      = new DateTimeImmutable('2026-01-01T00:00:00+00:00');
        $this->t1      = new DateTimeImmutable('2026-01-01T00:05:00+00:00');
    }

    private function state(SiteState $status, int $consecutiveFails = 0): SiteRuntimeState
    {
        return new SiteRuntimeState($status, $consecutiveFails, null, null, $this->t0, null);
    }
}

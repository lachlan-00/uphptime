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

namespace Uphptime\Tests\Scheduling;

use PHPUnit\Framework\TestCase;
use Uphptime\Scheduling\RunBudget;
use Uphptime\Tests\Support\FakeClock;

final class RunBudgetTest extends TestCase
{
    public function testExhaustedAtOrAfterDeadline(): void
    {
        $clock  = new FakeClock();
        $budget = new RunBudget($clock, 60);

        $clock->advance('+60 seconds');
        self::assertTrue($budget->isExhausted());

        $clock->advance('+1 second');
        self::assertTrue($budget->isExhausted());
    }

    public function testNotExhaustedBeforeDeadline(): void
    {
        $clock  = new FakeClock();
        $budget = new RunBudget($clock, 60);

        $clock->advance('+59 seconds');

        self::assertFalse($budget->isExhausted());
    }
}

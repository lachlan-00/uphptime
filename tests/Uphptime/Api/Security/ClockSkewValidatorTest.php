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

namespace Uphptime\Tests\Api\Security;

use DateTimeImmutable;
use PHPUnit\Framework\TestCase;
use Uphptime\Api\Security\ClockSkewValidator;

final class ClockSkewValidatorTest extends TestCase
{
    public function testExactlyAtTheBoundaryIsAccepted(): void
    {
        $validator = new ClockSkewValidator();
        $now       = new DateTimeImmutable('2026-01-01T00:01:00+00:00');

        self::assertTrue($validator->isWithinWindow($now, $now->getTimestamp() - 60, 60));
        self::assertTrue($validator->isWithinWindow($now, $now->getTimestamp() + 60, 60));
    }

    public function testFutureTimestampOutsideTheWindowIsRejected(): void
    {
        $validator = new ClockSkewValidator();
        $now       = new DateTimeImmutable('2026-01-01T00:00:00+00:00');

        self::assertFalse($validator->isWithinWindow($now, $now->getTimestamp() + 61, 60));
    }

    public function testPastTimestampOutsideTheWindowIsRejected(): void
    {
        $validator = new ClockSkewValidator();
        $now       = new DateTimeImmutable('2026-01-01T00:00:00+00:00');

        self::assertFalse($validator->isWithinWindow($now, $now->getTimestamp() - 61, 60));
    }

    public function testWithinTheWindowIsAccepted(): void
    {
        $validator = new ClockSkewValidator();
        $now       = new DateTimeImmutable('2026-01-01T00:00:00+00:00');

        self::assertTrue($validator->isWithinWindow($now, $now->getTimestamp() + 30, 60));
    }
}

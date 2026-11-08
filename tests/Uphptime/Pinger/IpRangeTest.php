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

namespace Uphptime\Tests\Pinger;

use PHPUnit\Framework\TestCase;
use Uphptime\Pinger\IpRange;

final class IpRangeTest extends TestCase
{
    public function testIpv4ExactBoundaryOfAPrefix(): void
    {
        $range = new IpRange('192.168.1.0/24');

        self::assertTrue($range->contains('192.168.1.0'));
        self::assertTrue($range->contains('192.168.1.255'));
        self::assertFalse($range->contains('192.168.2.0'));
    }

    public function testIpv4SlashThirtyTwoMatchesOnlyThatAddress(): void
    {
        $range = new IpRange('203.0.113.42/32');

        self::assertTrue($range->contains('203.0.113.42'));
        self::assertFalse($range->contains('203.0.113.43'));
    }

    public function testIpv6PrefixContainment(): void
    {
        $range = new IpRange('fe80::/10');

        self::assertTrue($range->contains('fe80::1'));
        self::assertFalse($range->contains('2001:db8::1'));
    }

    public function testMismatchedAddressFamilyNeverMatches(): void
    {
        $range = new IpRange('10.0.0.0/8');

        self::assertFalse($range->contains('::1'));
    }
}

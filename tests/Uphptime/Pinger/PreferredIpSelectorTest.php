<?php

declare(strict_types=1);

/**
 * vim:set softtabstop=4 shiftwidth=4 expandtab:
 *
 * LICENSE: GNU Affero General Public License, version 3 (AGPL-3.0-or-later)
 * Copyright uPHPtime contributors, 2026
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
use Uphptime\Pinger\PreferredIpSelector;
use Uphptime\Tests\Support\FakeNetworkStack;

final class PreferredIpSelectorTest extends TestCase
{
    public function testHasIpv6RouteKeepsTheFirstAddressRegardlessOfFamily(): void
    {
        $selector = new PreferredIpSelector(new FakeNetworkStack(true));

        self::assertSame('2001:db8::1', $selector->choose(['2001:db8::1', '203.0.113.9']));
    }

    public function testNoIpv6RoutePrefersTheFirstIpv4AddressEvenIfItsNotFirstInTheList(): void
    {
        $selector = new PreferredIpSelector(new FakeNetworkStack(false));

        self::assertSame('203.0.113.9', $selector->choose(['2001:db8::1', '203.0.113.9']));
    }

    public function testNoIpv6RouteWithOnlyAnIpv6AddressStillReturnsIt(): void
    {
        $selector = new PreferredIpSelector(new FakeNetworkStack(false));

        self::assertSame('2001:db8::1', $selector->choose(['2001:db8::1']));
    }

    public function testSingleIpv4AddressIsUnaffectedEitherWay(): void
    {
        self::assertSame('203.0.113.9', (new PreferredIpSelector(new FakeNetworkStack(false)))->choose(['203.0.113.9']));
        self::assertSame('203.0.113.9', (new PreferredIpSelector(new FakeNetworkStack(true)))->choose(['203.0.113.9']));
    }
}

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

namespace Uphptime\Tests\Pinger\Icmp;

use PHPUnit\Framework\TestCase;
use Uphptime\Check\Icmp\SystemPingClient;
use Uphptime\Pinger\BlockedRanges;
use Uphptime\Pinger\DefaultEgressPolicy;
use Uphptime\Pinger\Icmp\SafeIcmpClient;
use Uphptime\Pinger\PreferredIpSelector;
use Uphptime\Tests\Support\FakeDnsResolver;
use Uphptime\Tests\Support\FakeNetworkStack;

final class SafeIcmpClientTest extends TestCase
{
    public function testNoIpv6RoutePrefersTheIpv4AddressEvenWhenDnsListedItSecond(): void
    {
        $resolver = new FakeDnsResolver(['safe.test' => ['2001:db8::dead', '127.0.0.1']]);
        $policy   = new DefaultEgressPolicy(BlockedRanges::defaults(), ['127.0.0.0/8'], [], []);
        $client   = new SafeIcmpClient($resolver, $policy, new SystemPingClient(), new PreferredIpSelector(new FakeNetworkStack(false)));

        $client->ping('safe.test', 2.0);

        self::assertSame('127.0.0.1', $client->lastEvidence()?->resolvedIp);
    }
}

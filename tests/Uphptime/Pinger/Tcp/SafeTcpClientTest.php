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

namespace Uphptime\Tests\Pinger\Tcp;

use PHPUnit\Framework\TestCase;
use RuntimeException;
use Uphptime\Pinger\BlockedRanges;
use Uphptime\Pinger\DefaultEgressPolicy;
use Uphptime\Pinger\EgressPolicyException;
use Uphptime\Pinger\PreferredIpSelector;
use Uphptime\Pinger\Tcp\SafeTcpClient;
use Uphptime\Tests\Support\FakeDnsResolver;
use Uphptime\Tests\Support\FakeNetworkStack;

final class SafeTcpClientTest extends TestCase
{
    public function testAllowedPortOnAnAllowedTargetConnectsAndRecordsEvidence(): void
    {
        $listener = stream_socket_server('tcp://127.0.0.1:0');
        self::assertNotFalse($listener);
        $address = stream_socket_get_name($listener, false);
        $port    = (int) substr((string) $address, (int) strrpos((string) $address, ':') + 1);

        $resolver = new FakeDnsResolver(['safe.test' => ['127.0.0.1']]);
        $policy   = new DefaultEgressPolicy(BlockedRanges::defaults(), ['127.0.0.0/8'], [$port], []);
        $client   = new SafeTcpClient($resolver, $policy, new PreferredIpSelector(new FakeNetworkStack(true)));

        $client->connect('safe.test', $port, 2.0);

        $evidence = $client->lastEvidence();
        self::assertNotNull($evidence);
        self::assertSame('127.0.0.1', $evidence->resolvedIp);

        fclose($listener);
    }

    public function testBlockedIpIsRejectedBeforeConnecting(): void
    {
        $resolver = new FakeDnsResolver(['internal.test' => ['127.0.0.1']]);
        $policy   = new DefaultEgressPolicy(BlockedRanges::defaults(), [], [80], []);
        $client   = new SafeTcpClient($resolver, $policy, new PreferredIpSelector(new FakeNetworkStack(true)));

        $this->expectException(EgressPolicyException::class);
        $client->connect('internal.test', 80, 1.0);
    }

    public function testDisallowedPortIsRejectedBeforeConnecting(): void
    {
        $resolver = new FakeDnsResolver(['safe.test' => ['127.0.0.1']]);
        $policy   = new DefaultEgressPolicy(BlockedRanges::defaults(), ['127.0.0.0/8'], [443], []);
        $client   = new SafeTcpClient($resolver, $policy, new PreferredIpSelector(new FakeNetworkStack(true)));

        $this->expectException(EgressPolicyException::class);
        $client->connect('safe.test', 9999, 1.0);
    }

    public function testNoIpv6RoutePrefersTheIpv4AddressEvenWhenDnsListedItSecond(): void
    {
        $listener = stream_socket_server('tcp://127.0.0.1:0');
        self::assertNotFalse($listener);
        $address = stream_socket_get_name($listener, false);
        $port    = (int) substr((string) $address, (int) strrpos((string) $address, ':') + 1);

        $resolver = new FakeDnsResolver(['safe.test' => ['2001:db8::dead', '127.0.0.1']]);
        $policy   = new DefaultEgressPolicy(BlockedRanges::defaults(), ['127.0.0.0/8'], [$port], []);
        $client   = new SafeTcpClient($resolver, $policy, new PreferredIpSelector(new FakeNetworkStack(false)));

        $client->connect('safe.test', $port, 2.0);

        self::assertSame('127.0.0.1', $client->lastEvidence()?->resolvedIp);

        fclose($listener);
    }

    public function testUnreachablePortOnAnAllowedTargetFailsWithARuntimeException(): void
    {
        $resolver = new FakeDnsResolver(['safe.test' => ['127.0.0.1']]);
        $policy   = new DefaultEgressPolicy(BlockedRanges::defaults(), ['127.0.0.0/8'], [65533], []);
        $client   = new SafeTcpClient($resolver, $policy, new PreferredIpSelector(new FakeNetworkStack(true)));

        $this->expectException(RuntimeException::class);
        $client->connect('safe.test', 65533, 0.5);
    }
}

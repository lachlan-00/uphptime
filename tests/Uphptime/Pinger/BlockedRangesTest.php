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
use Uphptime\Pinger\BlockedRanges;
use Uphptime\Pinger\IpRange;

final class BlockedRangesTest extends TestCase
{
    /** @return array<string, array{0: string}> */
    public static function blockedAddressProvider(): array
    {
        return [
            'loopback v4' => ['127.0.0.1'],
            'link-local v4' => ['169.254.1.1'],
            'cloud metadata' => ['169.254.169.254'],
            'rfc1918 10/8' => ['10.0.0.1'],
            'rfc1918 172.16/12' => ['172.16.5.5'],
            'rfc1918 192.168/16' => ['192.168.1.1'],
            'cgnat' => ['100.64.0.1'],
            'multicast v4' => ['224.0.0.1'],
            'broadcast' => ['255.255.255.255'],
            'loopback v6' => ['::1'],
            'link-local v6' => ['fe80::1'],
            'unique-local v6' => ['fc00::1'],
            'multicast v6' => ['ff02::1'],
            'ipv4-mapped private' => ['::ffff:192.168.1.1'],
        ];
    }

    /** @return array<string, array{0: string}> */
    public static function publicAddressProvider(): array
    {
        return [
            'public v4' => ['93.184.216.34'],
            'public v6' => ['2606:2800:220:1:248:1893:25c8:1946'],
        ];
    }

    public function testDefaultsReturnsOnlyIpRangeInstances(): void
    {
        foreach (BlockedRanges::defaults() as $range) {
            self::assertInstanceOf(IpRange::class, $range);
        }
    }

    public function testEveryDefaultBlockedAddressIsBlocked(): void
    {
        foreach (self::blockedAddressProvider() as $label => [$ip]) {
            $blocked = false;
            foreach (BlockedRanges::defaults() as $range) {
                if ($range->contains($ip)) {
                    $blocked = true;
                    break;
                }
            }

            self::assertTrue($blocked, sprintf('%s (%s) must be blocked by a default range', $label, $ip));
        }
    }

    public function testPublicAddressesAreNotBlockedByAnyDefaultRange(): void
    {
        foreach (self::publicAddressProvider() as $label => [$ip]) {
            foreach (BlockedRanges::defaults() as $range) {
                self::assertFalse($range->contains($ip), sprintf('%s (%s) must not match a default-blocked range', $label, $ip));
            }
        }
    }
}

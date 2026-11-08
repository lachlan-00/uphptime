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
use Uphptime\Pinger\DefaultEgressPolicy;

final class DefaultEgressPolicyTest extends TestCase
{
    public function testAllowedRangesOverrideOverridesABlockedRange(): void
    {
        $policy = new DefaultEgressPolicy(BlockedRanges::defaults(), ['10.0.0.0/8'], [], []);

        self::assertFalse($policy->isIpBlocked('10.1.2.3'));
        self::assertTrue($policy->isIpBlocked('172.16.1.1'), 'an unrelated blocked range must still be blocked');
    }

    public function testEmptyTargetAllowlistAllowsAnyHost(): void
    {
        $policy = new DefaultEgressPolicy([], [], [], []);

        self::assertTrue($policy->isTargetAllowed('anything.example.test', '93.184.216.34'));
    }

    public function testNonEmptyTargetAllowlistAllowsAMatchingDomainAndDeniesOthers(): void
    {
        $policy = new DefaultEgressPolicy([], [], [], ['example.test']);

        self::assertTrue($policy->isTargetAllowed('example.test', '93.184.216.34'));
        self::assertTrue($policy->isTargetAllowed('sub.example.test', '93.184.216.34'), 'a subdomain of an allowed domain is allowed');
        self::assertFalse($policy->isTargetAllowed('not-example.test', '93.184.216.34'));
        self::assertFalse($policy->isTargetAllowed('other.test', '93.184.216.34'));
    }

    public function testTargetAllowlistCidrPatternMatchesOnTheResolvedIp(): void
    {
        $policy = new DefaultEgressPolicy([], [], [], ['93.184.216.0/24']);

        self::assertTrue($policy->isTargetAllowed('example.test', '93.184.216.34'));
        self::assertFalse($policy->isTargetAllowed('example.test', '8.8.8.8'));
    }

    public function testTcpPortAllowlistDeniesAPortNotInTheListEvenOnAPublicIp(): void
    {
        $policy = new DefaultEgressPolicy(BlockedRanges::defaults(), [], [443, 80], []);

        self::assertTrue($policy->isPortAllowed(443));
        self::assertFalse($policy->isPortAllowed(9999));
    }
}

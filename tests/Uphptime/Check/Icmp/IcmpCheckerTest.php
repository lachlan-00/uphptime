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

namespace Uphptime\Tests\Check\Icmp;

use DateTimeImmutable;
use PHPUnit\Framework\TestCase;
use Uphptime\Check\CheckConfigurationException;
use Uphptime\Check\CheckOutcome;
use Uphptime\Check\Icmp\IcmpChecker;
use Uphptime\Check\Icmp\SystemPingClient;
use Uphptime\Site\CheckType;
use Uphptime\Site\Site;
use Uphptime\Site\SiteRuntimeState;
use Uphptime\Site\SiteState;

final class IcmpCheckerTest extends TestCase
{
    public function testMissingHostThrowsConfigurationException(): void
    {
        $this->expectException(CheckConfigurationException::class);
        (new IcmpChecker(FakeIcmpClient::succeedingWith(1)))->check($this->site(null), 5.0);
    }

    public function testRealLoopbackPingIsDetectedAsPass(): void
    {
        $result = (new IcmpChecker(new SystemPingClient()))->check($this->site('127.0.0.1'), 2.0);

        self::assertSame(CheckOutcome::Pass, $result->outcome);
        self::assertNotNull($result->responseTimeMs);
    }

    public function testRealUnreachableHostIsDetectedAsFail(): void
    {
        $result = (new IcmpChecker(new SystemPingClient()))->check($this->site('192.0.2.1'), 1.0);

        self::assertSame(CheckOutcome::Fail, $result->outcome);
    }

    public function testSuccessfulPingPassesWithResponseTime(): void
    {
        $result = (new IcmpChecker(FakeIcmpClient::succeedingWith(12)))->check($this->site('127.0.0.1'), 5.0);

        self::assertSame(CheckOutcome::Pass, $result->outcome);
        self::assertSame(12, $result->responseTimeMs);
    }

    public function testUnreachableHostFails(): void
    {
        $result = (new IcmpChecker(FakeIcmpClient::failingWith('Host did not respond to ping: 10.0.0.1')))->check($this->site('10.0.0.1'), 5.0);

        self::assertSame(CheckOutcome::Fail, $result->outcome);
        self::assertSame('Host did not respond to ping: 10.0.0.1', $result->error);
    }

    private function site(?string $host): Site
    {
        return new Site(
            id: 'site-1',
            name: 'Example',
            url: null,
            host: $host,
            port: null,
            checkType: CheckType::Icmp,
            keyword: null,
            keywordMode: null,
            checkIntervalSeconds: null,
            enabled: true,
            createdAt: new DateTimeImmutable('2026-01-01T00:00:00+00:00'),
            state: new SiteRuntimeState(SiteState::Unknown, 0, null, null, new DateTimeImmutable('2026-01-01T00:00:00+00:00'), null),
        );
    }
}

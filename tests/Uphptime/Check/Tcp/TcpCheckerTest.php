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

namespace Uphptime\Tests\Check\Tcp;

use DateTimeImmutable;
use PHPUnit\Framework\TestCase;
use Uphptime\Check\CheckConfigurationException;
use Uphptime\Check\CheckOutcome;
use Uphptime\Check\Tcp\StreamTcpClient;
use Uphptime\Check\Tcp\TcpChecker;
use Uphptime\Site\CheckType;
use Uphptime\Site\Site;
use Uphptime\Site\SiteRuntimeState;
use Uphptime\Site\SiteState;

final class TcpCheckerTest extends TestCase
{
    public function testMissingHostOrPortThrowsConfigurationException(): void
    {
        $this->expectException(CheckConfigurationException::class);
        (new TcpChecker(FakeTcpClient::succeeding()))->check($this->site(null, null), 5.0);
    }

    public function testRealSocketAcceptIsDetectedAsPass(): void
    {
        $server = stream_socket_server('tcp://127.0.0.1:0', $errorNumber, $errorMessage);
        self::assertNotFalse($server, $errorMessage);

        $address       = stream_socket_get_name($server, false);
        [$host, $port] = explode(':', $address);

        $result = (new TcpChecker(new StreamTcpClient()))->check($this->site($host, (int) $port), 1.0);

        fclose($server);

        self::assertSame(CheckOutcome::Pass, $result->outcome);
    }

    public function testRealUnusedPortIsDetectedAsFail(): void
    {
        $server = stream_socket_server('tcp://127.0.0.1:0');
        self::assertNotFalse($server);
        $address       = stream_socket_get_name($server, false);
        [$host, $port] = explode(':', $address);
        fclose($server);

        $result = (new TcpChecker(new StreamTcpClient()))->check($this->site($host, (int) $port), 1.0);

        self::assertSame(CheckOutcome::Fail, $result->outcome);
    }

    public function testRefusedConnectFails(): void
    {
        $result = (new TcpChecker(FakeTcpClient::failingWith('Connection refused')))->check($this->site('localhost', 80), 5.0);

        self::assertSame(CheckOutcome::Fail, $result->outcome);
        self::assertSame('Connection refused', $result->error);
    }

    public function testSuccessfulConnectPasses(): void
    {
        $result = (new TcpChecker(FakeTcpClient::succeeding()))->check($this->site('localhost', 80), 5.0);

        self::assertSame(CheckOutcome::Pass, $result->outcome);
    }

    private function site(?string $host, ?int $port): Site
    {
        return new Site(
            id: 'site-1',
            name: 'Example',
            url: null,
            host: $host,
            port: $port,
            checkType: CheckType::Tcp,
            keyword: null,
            keywordMode: null,
            checkIntervalSeconds: null,
            enabled: true,
            createdAt: new DateTimeImmutable('2026-01-01T00:00:00+00:00'),
            state: new SiteRuntimeState(SiteState::Unknown, 0, null, null, new DateTimeImmutable('2026-01-01T00:00:00+00:00'), null),
        );
    }
}

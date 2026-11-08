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

namespace Uphptime\Tests\Check\Http;

use PHPUnit\Framework\TestCase;
use Uphptime\Check\Http\CurlHttpClient;
use Uphptime\Tests\Support\FakeNetworkStack;
use Uphptime\Tests\Support\SpawnedPhpServer;
use Uphptime\Version;

final class CurlHttpClientTest extends TestCase
{
    private static string $docRoot;
    private static SpawnedPhpServer $server;

    public static function setUpBeforeClass(): void
    {
        self::$docRoot = sys_get_temp_dir() . '/uphptime-curl-http-' . bin2hex(random_bytes(4));
        mkdir(self::$docRoot, 0775, true);
        file_put_contents(self::$docRoot . '/index.php', "<?php echo 'ok';");
        file_put_contents(self::$docRoot . '/agent.php', "<?php echo \$_SERVER['HTTP_USER_AGENT'] ?? '';");
        self::$server = new SpawnedPhpServer(self::$docRoot);
    }

    public static function tearDownAfterClass(): void
    {
        self::$server->stop();
        @unlink(self::$docRoot . '/index.php');
        @unlink(self::$docRoot . '/agent.php');
        @rmdir(self::$docRoot);
    }

    // Forcing IPv4 resolution (no local IPv6 route) must not break the plain, common case of
    // probing an IPv4 loopback target - this is the regression this whole feature must never cause.
    public function testForcingIpv4ResolutionStillReachesAnIpv4Target(): void
    {
        $client = new CurlHttpClient(new FakeNetworkStack(false));

        $response = $client->get(self::$server->baseUrl() . '/', 5.0, 'test-agent');

        self::assertSame(200, $response->statusCode);
        self::assertSame('ok', $response->body);
    }

    public function testHasIpv6RouteAlsoReachesAnIpv4Target(): void
    {
        $client = new CurlHttpClient(new FakeNetworkStack(true));

        $response = $client->get(self::$server->baseUrl() . '/', 5.0, 'test-agent');

        self::assertSame(200, $response->statusCode);
    }

    public function testUserAgentIsSentToTheTarget(): void
    {
        $client = new CurlHttpClient(new FakeNetworkStack(false));

        self::assertSame(Version::USER_AGENT . ' custom', $client->get(self::$server->baseUrl() . '/agent.php', 5.0, Version::userAgent('custom'))->body);
        self::assertSame(Version::USER_AGENT, $client->get(self::$server->baseUrl() . '/agent.php', 5.0, '')->body);
    }
}

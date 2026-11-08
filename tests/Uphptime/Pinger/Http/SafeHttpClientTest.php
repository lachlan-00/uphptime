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

namespace Uphptime\Tests\Pinger\Http;

use PHPUnit\Framework\TestCase;
use RuntimeException;
use Uphptime\Pinger\BlockedRanges;
use Uphptime\Pinger\DefaultEgressPolicy;
use Uphptime\Pinger\EgressPolicyException;
use Uphptime\Pinger\Http\SafeHttpClient;
use Uphptime\Pinger\PreferredIpSelector;
use Uphptime\Site\ChallengeDelivery;
use Uphptime\Tests\Support\FakeDnsResolver;
use Uphptime\Tests\Support\FakeNetworkStack;
use Uphptime\Tests\Support\SpawnedPhpServer;
use Uphptime\Version;

final class SafeHttpClientTest extends TestCase
{
    private static string $docRoot;
    private static SpawnedPhpServer $server;

    public static function setUpBeforeClass(): void
    {
        self::$docRoot = sys_get_temp_dir() . '/uphptime-safe-http-' . bin2hex(random_bytes(4));
        mkdir(self::$docRoot, 0775, true);

        file_put_contents(self::$docRoot . '/router.php', <<<'PHP'
            <?php
            $path = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
            if ($path === '/agent') {
                echo $_SERVER['HTTP_USER_AGENT'] ?? '';
                exit;
            }
            if ($path === '/redirect-to') {
                parse_str($_SERVER['QUERY_STRING'] ?? '', $query);
                header('Location: http://' . $query['host'] . ':' . $query['port'] . '/hello', true, 302);
                exit;
            }
            if ($path === '/redirect-malformed') {
                header('Location: http://user:password@safe.test:' . $_SERVER['SERVER_PORT'] . '/failure?token=secret value#fragment', true, 302);
                exit;
            }
            if ($path === '/big') {
                echo str_repeat('x', 1000);
                exit;
            }
            if ($path === '/header-challenge') {
                header('X-Uphptime-Challenge: observed-via-header');
                echo 'hello-world';
                exit;
            }
            if ($path === '/.well-known/uphptime') {
                echo 'observed-via-well-known';
                exit;
            }
            echo 'hello-world';
            PHP);

        self::$server = new SpawnedPhpServer(self::$docRoot, self::$docRoot . '/router.php');
    }

    public static function tearDownAfterClass(): void
    {
        self::$server->stop();
        foreach (glob(self::$docRoot . '/*') ?: [] as $file) {
            @unlink($file);
        }
        @rmdir(self::$docRoot);
    }

    public function testATrailingNewlineOnTheUrlDoesNotMakeCurlRejectTheRequest(): void
    {
        $resolver = new FakeDnsResolver(['safe.test' => ['127.0.0.1']]);
        $policy   = new DefaultEgressPolicy(BlockedRanges::defaults(), ['127.0.0.0/8'], [], []);
        $client   = new SafeHttpClient($resolver, $policy, 3, 1_000_000, new PreferredIpSelector(new FakeNetworkStack(true)));

        $response = $client->get(sprintf("http://safe.test:%d/hello\n", self::$server->port), 5.0, 'test-agent');

        self::assertSame(200, $response->statusCode);
    }

    public function testCurlFailureLogsTheRedirectHopWithoutSecrets(): void
    {
        $resolver = new FakeDnsResolver(['safe.test' => ['127.0.0.1']]);
        $policy   = new DefaultEgressPolicy(BlockedRanges::defaults(), ['127.0.0.0/8'], [], []);
        $client   = new SafeHttpClient($resolver, $policy, 3, 1_000_000, new PreferredIpSelector(new FakeNetworkStack(true)));
        $logFile  = tempnam(sys_get_temp_dir(), 'uphptime-http-log-');
        self::assertIsString($logFile);
        $previousLog = ini_set('error_log', $logFile);

        try {
            try {
                $client->get(sprintf('http://safe.test:%d/redirect-malformed', self::$server->port), 5.0, 'test-agent');
                self::fail('Expected cURL to reject the redirect.');
            } catch (RuntimeException $exception) {
                self::assertNotSame('', $exception->getMessage());
            }

            $log = file_get_contents($logFile);
            self::assertIsString($log);
            $json = strstr($log, '{');
            self::assertIsString($json);
            $entry = json_decode($json, true, 512, JSON_THROW_ON_ERROR);

            self::assertSame('pinger_http_curl_failure', $entry['event']);
            self::assertSame('check', $entry['operation']);
            self::assertSame('safe.test', $entry['host']);
            self::assertSame('/failure', $entry['path']);
            self::assertSame(1, $entry['redirects_followed']);
            self::assertSame(3, $entry['max_redirects']);
            self::assertSame(CURLE_URL_MALFORMAT, $entry['curl_errno']);
            self::assertTrue($entry['query_present']);
            self::assertTrue($entry['query_contains_whitespace']);
            self::assertStringNotContainsString('secret', $log);
            self::assertStringNotContainsString('password', $log);
            self::assertStringNotContainsString('fragment', $log);
        } finally {
            if ($previousLog !== false) {
                ini_set('error_log', $previousLog);
            }
            unlink($logFile);
        }
    }

    public function testHeaderDeliveryObservesTheChallengeTokenFromTheResponseHeader(): void
    {
        $resolver = new FakeDnsResolver(['safe.test' => ['127.0.0.1']]);
        $policy   = new DefaultEgressPolicy(BlockedRanges::defaults(), ['127.0.0.0/8'], [], []);
        $client   = new SafeHttpClient($resolver, $policy, 3, 1_000_000, new PreferredIpSelector(new FakeNetworkStack(true)));

        $client->get(sprintf('http://safe.test:%d/header-challenge', self::$server->port), 5.0, 'test-agent', ChallengeDelivery::Header);

        self::assertSame('observed-via-header', $client->lastEvidence()?->challengeTokenObserved);
    }

    public function testHostnameIsResolvedExactlyOnceEvenAcrossARedirect(): void
    {
        $resolver = new FakeDnsResolver(['safe.test' => ['127.0.0.1'], 'safe-two.test' => ['127.0.0.1']]);
        $policy   = new DefaultEgressPolicy(BlockedRanges::defaults(), ['127.0.0.0/8'], [], []);
        $client   = new SafeHttpClient($resolver, $policy, 3, 1_000_000, new PreferredIpSelector(new FakeNetworkStack(true)));

        $client->get(sprintf('http://safe.test:%d/redirect-to?host=safe-two.test&port=%d', self::$server->port, self::$server->port), 5.0, 'test-agent');

        self::assertSame(1, $resolver->callCountFor('safe.test'));
        self::assertSame(1, $resolver->callCountFor('safe-two.test'));
    }

    public function testNoChallengeDeliveryObservesNoToken(): void
    {
        $resolver = new FakeDnsResolver(['safe.test' => ['127.0.0.1']]);
        $policy   = new DefaultEgressPolicy(BlockedRanges::defaults(), ['127.0.0.0/8'], [], []);
        $client   = new SafeHttpClient($resolver, $policy, 3, 1_000_000, new PreferredIpSelector(new FakeNetworkStack(true)));

        $client->get(sprintf('http://safe.test:%d/header-challenge', self::$server->port), 5.0, 'test-agent');

        self::assertNull($client->lastEvidence()?->challengeTokenObserved);
    }

    public function testNoIpv6RoutePrefersTheIpv4AddressEvenWhenDnsListedItSecond(): void
    {
        $resolver = new FakeDnsResolver(['safe.test' => ['2001:db8::dead', '127.0.0.1']]);
        $policy   = new DefaultEgressPolicy(BlockedRanges::defaults(), ['127.0.0.0/8'], [], []);
        $client   = new SafeHttpClient($resolver, $policy, 3, 1_000_000, new PreferredIpSelector(new FakeNetworkStack(false)));

        $response = $client->get(sprintf('http://safe.test:%d/hello', self::$server->port), 5.0, 'test-agent');

        self::assertSame(200, $response->statusCode);
        self::assertSame('127.0.0.1', $client->lastEvidence()?->resolvedIp);
    }

    public function testOversizedResponseAbortsTheTransferRatherThanReturningTruncatedData(): void
    {
        $resolver = new FakeDnsResolver(['safe.test' => ['127.0.0.1']]);
        $policy   = new DefaultEgressPolicy(BlockedRanges::defaults(), ['127.0.0.0/8'], [], []);
        $client   = new SafeHttpClient($resolver, $policy, 3, 10, new PreferredIpSelector(new FakeNetworkStack(true)));

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('size cap');
        $client->get(sprintf('http://safe.test:%d/big', self::$server->port), 5.0, 'test-agent');
    }

    public function testRedirectToAHostThatResolvesToABlockedIpIsRejectedRatherThanFollowed(): void
    {
        $resolver = new FakeDnsResolver(['safe.test' => ['127.0.0.1'], 'internal.test' => ['10.0.0.5']]);
        $policy   = new DefaultEgressPolicy(BlockedRanges::defaults(), ['127.0.0.0/8'], [], []);
        $client   = new SafeHttpClient($resolver, $policy, 3, 1_000_000, new PreferredIpSelector(new FakeNetworkStack(true)));

        $this->expectException(EgressPolicyException::class);
        $client->get(sprintf('http://safe.test:%d/redirect-to?host=internal.test&port=80', self::$server->port), 5.0, 'test-agent');
    }

    public function testSuccessfulProbePopulatesEvidence(): void
    {
        $resolver = new FakeDnsResolver(['safe.test' => ['127.0.0.1']]);
        $policy   = new DefaultEgressPolicy(BlockedRanges::defaults(), ['127.0.0.0/8'], [], []);
        $client   = new SafeHttpClient($resolver, $policy, 3, 1_000_000, new PreferredIpSelector(new FakeNetworkStack(true)));

        $response = $client->get(sprintf('http://safe.test:%d/hello', self::$server->port), 5.0, 'test-agent');
        $evidence = $client->lastEvidence();

        self::assertSame(200, $response->statusCode);
        self::assertNotNull($evidence);
        self::assertSame('127.0.0.1', $evidence->resolvedIp);
        self::assertIsInt($evidence->timingMs['total']);
    }

    public function testUserAgentIsSentToTheTarget(): void
    {
        $resolver = new FakeDnsResolver(['safe.test' => ['127.0.0.1']]);
        $policy   = new DefaultEgressPolicy(BlockedRanges::defaults(), ['127.0.0.0/8'], [], []);
        $client   = new SafeHttpClient($resolver, $policy, 0, 1_000_000, new PreferredIpSelector(new FakeNetworkStack(true)));
        $url      = sprintf('http://safe.test:%d/agent', self::$server->port);

        self::assertSame(Version::USER_AGENT . ' custom', $client->get($url, 5.0, Version::userAgent('custom'))->body);
        self::assertSame(Version::USER_AGENT, $client->get($url, 5.0, '')->body);
    }

    public function testWellKnownDeliveryFetchesTheChallengeTokenFromTheWellKnownPath(): void
    {
        $resolver = new FakeDnsResolver(['safe.test' => ['127.0.0.1']]);
        $policy   = new DefaultEgressPolicy(BlockedRanges::defaults(), ['127.0.0.0/8'], [], []);
        $client   = new SafeHttpClient($resolver, $policy, 3, 1_000_000, new PreferredIpSelector(new FakeNetworkStack(true)));

        $client->get(sprintf('http://safe.test:%d/hello', self::$server->port), 5.0, 'test-agent', ChallengeDelivery::WellKnown);

        self::assertSame('observed-via-well-known', $client->lastEvidence()?->challengeTokenObserved);
    }

    public function testZeroRedirectLimitReturnsTheInitialRedirect(): void
    {
        $resolver = new FakeDnsResolver(['safe.test' => ['127.0.0.1'], 'safe-two.test' => ['127.0.0.1']]);
        $policy   = new DefaultEgressPolicy(BlockedRanges::defaults(), ['127.0.0.0/8'], [], []);
        $client   = new SafeHttpClient($resolver, $policy, 0, 1_000_000, new PreferredIpSelector(new FakeNetworkStack(true)));

        $response = $client->get(sprintf('http://safe.test:%d/redirect-to?host=safe-two.test&port=%d', self::$server->port, self::$server->port), 5.0, 'test-agent');

        self::assertSame(302, $response->statusCode);
        self::assertSame(1, $resolver->callCountFor('safe.test'));
        self::assertSame(0, $resolver->callCountFor('safe-two.test'));
    }
}

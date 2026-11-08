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

namespace Uphptime\Tests\Api\Actions;

use DateTimeImmutable;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ServerRequestInterface;
use Slim\Psr7\Factory\ResponseFactory;
use Slim\Psr7\Factory\ServerRequestFactory;
use Slim\Psr7\Factory\StreamFactory;
use Uphptime\Api\Actions\CheckSiteAction;
use Uphptime\Peer\Peer;
use Uphptime\Peer\PeerKey;
use Uphptime\Pinger\Cache\CacheRefresher;
use Uphptime\Pinger\Cache\JsonCheckCacheRepository;
use Uphptime\Pinger\CachingDelegatedCheckExecutor;
use Uphptime\Pinger\DelegatedCheckExecutor;
use Uphptime\Pinger\PreferredIpSelector;
use Uphptime\Support\AtomicWriter;
use Uphptime\Tests\Support\FakeClock;
use Uphptime\Tests\Support\FakeDnsResolver;
use Uphptime\Tests\Support\FakeNetworkStack;
use Uphptime\Tests\Support\SpawnedPhpServer;

final class CheckSiteActionTest extends TestCase
{
    private static string $docRoot;
    private static SpawnedPhpServer $server;

    public static function setUpBeforeClass(): void
    {
        self::$docRoot = sys_get_temp_dir() . '/uphptime-check-action-' . bin2hex(random_bytes(4));
        mkdir(self::$docRoot, 0775, true);
        file_put_contents(self::$docRoot . '/index.php', <<<'PHP'
            <?php
            $path = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
            if ($path === '/redirect-malformed') {
                header('Location: http://safe.test:' . $_SERVER['SERVER_PORT'] . '/login?value=unescaped space', true, 302);
                exit;
            }
            if ($path === '/redirect-keyword') {
                header('Location: /keyword', true, 302);
                exit;
            }
            if ($path === '/redirect-loop') {
                header('Location: /redirect-loop', true, 302);
                exit;
            }
            if ($path === '/redirect-without-location') {
                http_response_code(302);
                exit;
            }
            echo $path === '/keyword' ? 'needle' : 'ok';
            PHP);
        self::$server = new SpawnedPhpServer(self::$docRoot);
    }

    public static function tearDownAfterClass(): void
    {
        self::$server->stop();
        foreach (glob(self::$docRoot . '/*') ?: [] as $file) {
            @unlink($file);
        }
        @rmdir(self::$docRoot);
    }

    public function testEgressBlockedTargetMapsToErrorNeverFail(): void
    {
        $action = $this->action(new FakeDnsResolver(['internal.test' => ['10.0.0.5']]));

        $response = $action->handle($this->requestWithBody(['check_type' => 'http', 'url' => 'http://internal.test/']));
        $body     = json_decode((string) $response->getBody(), true);

        self::assertSame(200, $response->getStatusCode());
        self::assertSame('error', $body['outcome']);
    }

    public function testKeywordCheckStillFollowsConfiguredRedirects(): void
    {
        $resolver = new FakeDnsResolver(['safe.test' => ['127.0.0.1']]);
        $action   = $this->action($resolver);

        $response = $action->handle($this->requestWithBody([
            'check_type' => 'http_keyword',
            'url' => sprintf('http://safe.test:%d/redirect-keyword', self::$server->port),
            'keyword' => 'needle',
            'keyword_mode' => 'contains',
        ]));
        $body = json_decode((string) $response->getBody(), true);

        self::assertSame('pass', $body['outcome']);
        self::assertSame(200, $body['http_status']);
        self::assertTrue($body['keyword_matched']);
        self::assertSame(2, $resolver->callCountFor('safe.test'));
    }

    public function testMalformedJsonIsRejectedWith422(): void
    {
        $action   = $this->action(new FakeDnsResolver([]));
        $response = $action->handle($this->requestWithRawBody('not json'));

        self::assertSame(422, $response->getStatusCode());
    }

    public function testMissingHostPortForTcpIsRejectedWith422(): void
    {
        $action   = $this->action(new FakeDnsResolver([]));
        $response = $action->handle($this->requestWithBody(['check_type' => 'tcp', 'host' => null, 'port' => null]));

        self::assertSame(422, $response->getStatusCode());
    }

    public function testPlainHttpAcceptsRedirectWithoutFollowingWhenLimitIsZero(): void
    {
        $resolver = new FakeDnsResolver(['safe.test' => ['127.0.0.1']]);
        $action   = $this->action($resolver, 0);

        $response = $action->handle($this->requestWithBody([
            'check_type' => 'http',
            'url' => sprintf('http://safe.test:%d/redirect-malformed', self::$server->port),
        ]));
        $body = json_decode((string) $response->getBody(), true);

        self::assertSame('pass', $body['outcome']);
        self::assertSame(302, $body['http_status']);
        self::assertSame(1, $resolver->callCountFor('safe.test'));
        self::assertIsArray($body['evidence']);
    }

    public function testPlainHttpFailsForRedirectWithoutLocationWhenFollowingEnabled(): void
    {
        $action   = $this->action(new FakeDnsResolver(['safe.test' => ['127.0.0.1']]), 3);
        $response = $action->handle($this->requestWithBody([
            'check_type' => 'http',
            'url' => sprintf('http://safe.test:%d/redirect-without-location', self::$server->port),
        ]));
        $body = json_decode((string) $response->getBody(), true);

        self::assertSame('fail', $body['outcome']);
        self::assertSame('Redirect response is missing a Location header.', $body['error']);
    }

    public function testPlainHttpFailsWhenRedirectLimitIsExhausted(): void
    {
        $resolver = new FakeDnsResolver(['safe.test' => ['127.0.0.1']]);
        $action   = $this->action($resolver, 1);

        $response = $action->handle($this->requestWithBody([
            'check_type' => 'http',
            'url' => sprintf('http://safe.test:%d/redirect-loop', self::$server->port),
        ]));
        $body = json_decode((string) $response->getBody(), true);

        self::assertSame('fail', $body['outcome']);
        self::assertSame('Redirect limit of 1 exceeded.', $body['error']);
        self::assertSame(2, $resolver->callCountFor('safe.test'));
    }

    public function testPlainHttpFollowsRedirectWhenLimitIsPositive(): void
    {
        $resolver = new FakeDnsResolver(['safe.test' => ['127.0.0.1']]);
        $action   = $this->action($resolver, 3);

        $response = $action->handle($this->requestWithBody([
            'check_type' => 'http',
            'url' => sprintf('http://safe.test:%d/redirect-keyword', self::$server->port),
        ]));
        $body = json_decode((string) $response->getBody(), true);

        self::assertSame('pass', $body['outcome']);
        self::assertSame(200, $body['http_status']);
        self::assertSame(2, $resolver->callCountFor('safe.test'));
    }

    public function testSecondRequestWithinTheCacheTtlIsServedFromCache(): void
    {
        $directory = sys_get_temp_dir() . '/uphptime-check-action-cache-' . bin2hex(random_bytes(4));
        mkdir($directory, 0775, true);

        $clock     = new FakeClock();
        $writer    = new AtomicWriter();
        $plain     = new DelegatedCheckExecutor(new FakeDnsResolver(['safe.test' => ['127.0.0.1']]), 3, 1_000_000, 'test-agent', [21, 22, 25, 53, 80, 110, 143, 443], ['127.0.0.0/8'], new PreferredIpSelector(new FakeNetworkStack(true)));
        $executor  = new CachingDelegatedCheckExecutor($plain, new CacheRefresher($writer, $directory), new JsonCheckCacheRepository($writer, $directory), $clock, 60, 500);
        $action    = new CheckSiteAction($executor, 15, new ResponseFactory());
        $payload   = ['check_type' => 'http', 'url' => sprintf('http://safe.test:%d/', self::$server->port)];

        $first = json_decode((string) $action->handle($this->requestWithRawBody((string) json_encode($payload)))->getBody(), true);
        $clock->advance('+5 seconds');
        $second = json_decode((string) $action->handle($this->requestWithRawBody((string) json_encode($payload)))->getBody(), true);

        self::assertFalse($first['served_from_cache']);
        self::assertTrue($second['served_from_cache']);
        self::assertSame(5, $second['cache_age_seconds']);
        self::assertSame($first['evidence']['probe_started_at'], $second['evidence']['probe_started_at']);

        foreach (glob($directory . '/*') ?: [] as $file) {
            @unlink($file);
        }
        @rmdir($directory);
    }

    public function testUnrecognizedCheckTypeIsRejectedWith422(): void
    {
        $action   = $this->action(new FakeDnsResolver([]));
        $response = $action->handle($this->requestWithBody(['check_type' => 'ping']));

        self::assertSame(422, $response->getStatusCode());
    }

    public function testValidHttpCheckAgainstAnAllowedTargetReturnsPass(): void
    {
        $action = $this->action(new FakeDnsResolver(['safe.test' => ['127.0.0.1']]));

        $response = $action->handle($this->requestWithBody([
            'check_type' => 'http',
            'url' => sprintf('http://safe.test:%d/', self::$server->port),
        ]));
        $body = json_decode((string) $response->getBody(), true);

        self::assertSame(200, $response->getStatusCode());
        self::assertSame('pass', $body['outcome']);
        self::assertSame('none', $body['attestation']);
        self::assertIsArray($body['evidence']);
        self::assertFalse($body['served_from_cache']);
        self::assertSame(0, $body['cache_age_seconds']);
    }

    private function action(FakeDnsResolver $resolver, int $maxRedirects = 3): CheckSiteAction
    {
        $executor = new DelegatedCheckExecutor($resolver, $maxRedirects, 1_000_000, 'test-agent', [21, 22, 25, 53, 80, 110, 143, 443], ['127.0.0.0/8'], new PreferredIpSelector(new FakeNetworkStack(true)));

        return new CheckSiteAction($executor, 15, new ResponseFactory());
    }

    private function peerAttribute(): Peer
    {
        return new Peer('peer-a', 'Peer A', null, true, true, null, [], [new PeerKey('k1', 'secret', true, true, true, new DateTimeImmutable('2026-01-01T00:00:00+00:00'))]);
    }

    private function requestWithBody(array $payload): ServerRequestInterface
    {
        return $this->requestWithRawBody((string) json_encode($payload));
    }

    private function requestWithRawBody(string $body): ServerRequestInterface
    {
        $request = (new ServerRequestFactory())->createServerRequest('POST', '/peer/v1/check-site');
        $request = $request->withBody((new StreamFactory())->createStream($body));

        return $request->withAttribute('uphptime.peer', $this->peerAttribute());
    }
}

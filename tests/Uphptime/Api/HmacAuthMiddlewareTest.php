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

namespace Uphptime\Tests\Api;

use DateTimeImmutable;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ServerRequestInterface;
use Slim\Psr7\Factory\ResponseFactory;
use Slim\Psr7\Factory\ServerRequestFactory;
use Slim\Psr7\Factory\StreamFactory;
use Uphptime\Api\HmacAuthMiddleware;
use Uphptime\Api\Security\ClockSkewValidator;
use Uphptime\Api\Security\JsonNonceStore;
use Uphptime\Api\Security\JsonRateLimiter;
use Uphptime\Api\Signing\HmacSigner;
use Uphptime\Api\Signing\RequestCanonicalizer;
use Uphptime\Api\Signing\ResponseCanonicalizer;
use Uphptime\Api\Signing\SignatureHeaders;
use Uphptime\Config\Config;
use Uphptime\Peer\Peer;
use Uphptime\Peer\PeerKey;
use Uphptime\Support\AtomicWriter;
use Uphptime\Tests\Storage\InMemoryPeerRepository;
use Uphptime\Tests\Support\FakeClock;

final class HmacAuthMiddlewareTest extends TestCase
{
    private const SECRET = 'top-secret';

    private string $directory;
    private InMemoryPeerRepository $peers;

    /** @return array<string, array{0: callable}> */
    public static function unauthorizedScenarioProvider(): array
    {
        return [
            'unknown key id' => [static fn(self $t) => $t->requestWithHeaders(['X-Uphptime-Key-Id' => 'does-not-exist'])],
            'stale timestamp' => [static fn(self $t) => $t->signedRequest('2020-01-01T00:00:00+00:00', 'n', 'r')],
            'tampered signature' => [static fn(self $t) => $t->requestWithTamperedSignature()],
        ];
    }

    public function requestWithHeaders(array $overrides): ServerRequestInterface
    {
        $headers = array_merge($this->validHeaders('2026-01-01T00:00:00+00:00', 'n', 'r'), $overrides);

        return $this->requestFor($headers, '{}');
    }

    public function requestWithTamperedSignature(): ServerRequestInterface
    {
        $headers                                 = $this->validHeaders('2026-01-01T00:00:00+00:00', 'n', 'r');
        $headers[SignatureHeaders::SIGNATURE]    = str_repeat('0', 64);

        return $this->requestFor($headers, '{}');
    }

    public function signedRequest(string $timestamp, string $nonce, string $requestId): ServerRequestInterface
    {
        return $this->requestFor($this->validHeaders($timestamp, $nonce, $requestId), '{}');
    }

    public function testDuplicateNonceProducesTheSameUniform401(): void
    {
        $middleware = $this->middleware();
        $this->seedPeer();

        $request1 = $this->signedRequest('2026-01-01T00:00:00+00:00', 'nonce-dup', 'r1');
        $middleware->process($request1, new FakeRequestHandler());

        $request2 = $this->signedRequest('2026-01-01T00:00:00+00:00', 'nonce-dup', 'r2');
        $handler2 = new FakeRequestHandler();
        $response = $middleware->process($request2, $handler2);

        self::assertFalse($handler2->wasCalled);
        self::assertSame(401, $response->getStatusCode());
        self::assertSame('{"error":"unauthorized"}', (string) $response->getBody());
    }

    /** @dataProvider unauthorizedScenarioProvider */
    public function testEachAuthFailureReasonProducesTheByteIdenticalUniform401(callable $buildRequest): void
    {
        $this->seedPeer();
        $middleware = $this->middleware();
        $handler    = new FakeRequestHandler();

        $response = $middleware->process($buildRequest($this), $handler);

        self::assertFalse($handler->wasCalled);
        self::assertSame(401, $response->getStatusCode());
        self::assertSame('{"error":"unauthorized"}', (string) $response->getBody());
    }

    public function testOverLimitReturnsADistinct429(): void
    {
        $middleware = $this->middleware(rateLimitPerMinute: 1);
        $this->seedPeer();

        $middleware->process($this->signedRequest('2026-01-01T00:00:00+00:00', 'n1', 'r1'), new FakeRequestHandler());
        $response = $middleware->process($this->signedRequest('2026-01-01T00:00:00+00:00', 'n2', 'r2'), new FakeRequestHandler());

        self::assertSame(429, $response->getStatusCode());
        self::assertNotSame('{"error":"unauthorized"}', (string) $response->getBody());
    }

    public function testValidSignedRequestReachesTheInnerHandlerAndSignsTheResponse(): void
    {
        $middleware = $this->middleware();
        $this->seedPeer();
        $handler = new FakeRequestHandler();

        $response = $middleware->process($this->signedRequest('2026-01-01T00:00:00+00:00', 'n1', 'req-1'), $handler);

        self::assertTrue($handler->wasCalled);
        self::assertSame(200, $response->getStatusCode());

        $signature         = $response->getHeaderLine(SignatureHeaders::SIGNATURE);
        $responseTimestamp = $response->getHeaderLine(SignatureHeaders::RESPONSE_TIMESTAMP);
        $canonical         = (new ResponseCanonicalizer())->canonicalize('req-1', $responseTimestamp, 200, (string) $response->getBody());

        self::assertTrue((new HmacSigner())->verify($canonical, self::SECRET, $signature));
    }

    protected function setUp(): void
    {
        $this->directory = sys_get_temp_dir() . '/uphptime-hmac-mw-' . bin2hex(random_bytes(4));
        mkdir($this->directory, 0775, true);
        $this->peers = new InMemoryPeerRepository();
    }

    protected function tearDown(): void
    {
        foreach (glob($this->directory . '/*/*') ?: [] as $file) {
            @unlink($file);
        }
        foreach (glob($this->directory . '/*') ?: [] as $dir) {
            @rmdir($dir);
        }
        @rmdir($this->directory);
    }

    private function middleware(int $rateLimitPerMinute = 60): HmacAuthMiddleware
    {
        $config = new Config('data', 300, 90, 10, 240, 900, 'ua', 'UTC', defaultPeerRateLimitPerMinute: $rateLimitPerMinute);
        $writer = new AtomicWriter();

        return new HmacAuthMiddleware(
            $this->peers,
            new HmacSigner(),
            new RequestCanonicalizer(),
            new ResponseCanonicalizer(),
            new JsonNonceStore($writer, $this->directory . '/nonces'),
            new JsonRateLimiter($writer, $this->directory . '/rate_limits'),
            new ClockSkewValidator(),
            new FakeClock('2026-01-01T00:00:00+00:00'),
            $config,
            new ResponseFactory(),
        );
    }

    private function requestFor(array $headers, string $body): ServerRequestInterface
    {
        $request = (new ServerRequestFactory())->createServerRequest('POST', '/peer/v1/check-site');
        $request = $request->withBody((new StreamFactory())->createStream($body));
        foreach ($headers as $name => $value) {
            $request = $request->withHeader($name, $value);
        }

        return $request;
    }

    private function seedPeer(): Peer
    {
        $peer = new Peer(
            'peer-a',
            'Peer A',
            'https://peer-a.test',
            true,
            true,
            null,
            [],
            [new PeerKey('k1', self::SECRET, true, true, true, new DateTimeImmutable('2026-01-01T00:00:00+00:00'))],
        );
        $this->peers->save($peer);

        return $peer;
    }

    /** @return array<string, string> */
    private function validHeaders(string $timestamp, string $nonce, string $requestId): array
    {
        $ts        = (string) (new DateTimeImmutable($timestamp))->getTimestamp();
        $canonical = (new RequestCanonicalizer())->canonicalize('POST', '/peer/v1/check-site', 'k1', $ts, $nonce, $requestId, '{}');
        $signature = (new HmacSigner())->sign($canonical, self::SECRET);

        return [
            SignatureHeaders::KEY_ID => 'k1',
            SignatureHeaders::TIMESTAMP => $ts,
            SignatureHeaders::NONCE => $nonce,
            SignatureHeaders::REQUEST_ID => $requestId,
            SignatureHeaders::SIGNATURE => $signature,
        ];
    }
}

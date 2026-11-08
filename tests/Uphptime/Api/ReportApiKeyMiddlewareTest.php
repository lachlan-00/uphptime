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

namespace Uphptime\Tests\Api;

use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ServerRequestInterface;
use Slim\Psr7\Factory\ResponseFactory;
use Slim\Psr7\Factory\ServerRequestFactory;
use Uphptime\Api\ReportApiKeyMiddleware;
use Uphptime\Config\Config;

final class ReportApiKeyMiddlewareTest extends TestCase
{
    public function testCorrectBearerKeyReachesTheHandlerAndCarriesCorsHeaders(): void
    {
        $middleware = $this->middleware('the-secret');
        $handler    = new FakeRequestHandler();

        $response = $middleware->process($this->requestWithAuthorization('Bearer the-secret'), $handler);

        self::assertTrue($handler->wasCalled);
        self::assertSame(200, $response->getStatusCode());
        self::assertSame('*', $response->getHeaderLine('Access-Control-Allow-Origin'));
    }

    public function testEmptyConfiguredKeyIs404WithoutReachingTheHandler(): void
    {
        $middleware = $this->middleware('');
        $handler    = new FakeRequestHandler();

        $response = $middleware->process($this->requestWithAuthorization('Bearer anything'), $handler);

        self::assertFalse($handler->wasCalled);
        self::assertSame(404, $response->getStatusCode());
    }

    public function testMissingAuthorizationHeaderIs401(): void
    {
        $middleware = $this->middleware('the-secret');
        $handler    = new FakeRequestHandler();

        $response = $middleware->process((new ServerRequestFactory())->createServerRequest('GET', '/report/v1/overview'), $handler);

        self::assertFalse($handler->wasCalled);
        self::assertSame(401, $response->getStatusCode());
    }

    public function testOptionsRequestIsAnsweredWithoutReachingTheHandler(): void
    {
        $middleware = $this->middleware('the-secret');
        $handler    = new FakeRequestHandler();

        $request  = (new ServerRequestFactory())->createServerRequest('OPTIONS', '/report/v1/overview');
        $response = $middleware->process($request, $handler);

        self::assertFalse($handler->wasCalled);
        self::assertSame(204, $response->getStatusCode());
        self::assertSame('*', $response->getHeaderLine('Access-Control-Allow-Origin'));
    }

    public function testWrongBearerKeyIs401(): void
    {
        $middleware = $this->middleware('the-secret');
        $handler    = new FakeRequestHandler();

        $response = $middleware->process($this->requestWithAuthorization('Bearer wrong'), $handler);

        self::assertFalse($handler->wasCalled);
        self::assertSame(401, $response->getStatusCode());
    }

    private function middleware(string $reportApiKey): ReportApiKeyMiddleware
    {
        return new ReportApiKeyMiddleware(
            new Config('data', 300, 90, 10, 240, 900, 'uphptime-test', 'UTC', reportApiKey: $reportApiKey),
            new ResponseFactory(),
        );
    }

    private function requestWithAuthorization(string $value): ServerRequestInterface
    {
        return (new ServerRequestFactory())->createServerRequest('GET', '/report/v1/overview')->withHeader('Authorization', $value);
    }
}

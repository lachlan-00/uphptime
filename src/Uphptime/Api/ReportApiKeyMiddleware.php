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

namespace Uphptime\Api;

use Psr\Http\Message\ResponseFactoryInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Uphptime\Config\Config;

/**
 * Guards the report API with a single shared bearer key, not HMAC: this is an owner-facing dashboard
 * credential, not a pairwise peer trust relationship. An empty configured key disables the route (404)
 * rather than leaving it reachable with no real gate; a wrong key is a uniform 401, same spirit as
 * HmacAuthMiddleware. Also answers CORS preflight, since the dashboard is a separate-origin static site.
 */
final class ReportApiKeyMiddleware implements MiddlewareInterface
{
    public function __construct(
        private readonly Config $config,
        private readonly ResponseFactoryInterface $responseFactory,
    ) {}

    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        if (strtoupper($request->getMethod()) === 'OPTIONS') {
            return $this->withCors($this->responseFactory->createResponse(204));
        }

        if ($this->config->reportApiKey === '') {
            return $this->withCors($this->jsonResponse(404, ['error' => 'not_found']));
        }

        $provided = $this->bearerToken($request->getHeaderLine('Authorization'));
        if ($provided === null || !hash_equals($this->config->reportApiKey, $provided)) {
            return $this->withCors($this->jsonResponse(401, ['error' => 'unauthorized']));
        }

        return $this->withCors($handler->handle($request));
    }

    private function bearerToken(string $authorizationHeader): ?string
    {
        return str_starts_with($authorizationHeader, 'Bearer ') ? substr($authorizationHeader, 7) : null;
    }

    /** @param array<string, mixed> $body */
    private function jsonResponse(int $status, array $body): ResponseInterface
    {
        $response = $this->responseFactory->createResponse($status);
        $response->getBody()->write((string) json_encode($body, JSON_THROW_ON_ERROR));

        return $response->withHeader('Content-Type', 'application/json');
    }

    private function withCors(ResponseInterface $response): ResponseInterface
    {
        return $response
            ->withHeader('Access-Control-Allow-Origin', $this->config->reportCorsAllowedOrigin)
            ->withHeader('Access-Control-Allow-Methods', 'GET, OPTIONS')
            ->withHeader('Access-Control-Allow-Headers', 'Authorization')
            ->withHeader('Access-Control-Max-Age', '86400');
    }
}

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

namespace Uphptime\Api;

use Psr\Http\Message\ResponseFactoryInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Uphptime\Api\Security\ClockSkewValidator;
use Uphptime\Api\Security\NonceStoreInterface;
use Uphptime\Api\Security\RateLimiterInterface;
use Uphptime\Api\Signing\HmacSignerInterface;
use Uphptime\Api\Signing\RequestCanonicalizer;
use Uphptime\Api\Signing\ResponseCanonicalizer;
use Uphptime\Api\Signing\SignatureHeaders;
use Uphptime\Config\Config;
use Uphptime\Storage\PeerRepositoryInterface;
use Uphptime\Support\ClockInterface;

/**
 * Verifies an inbound signed request (key lookup, clock skew, signature, nonce, rate limit) and signs
 * the outbound response with the same peer's key. Every auth failure reason answers with the byte-
 * identical uniform 401 so a client can't learn which check failed; rate-limiting is a distinct,
 * non-secret 429.
 */
final class HmacAuthMiddleware implements MiddlewareInterface
{
    public function __construct(
        private readonly PeerRepositoryInterface $peerRepository,
        private readonly HmacSignerInterface $signer,
        private readonly RequestCanonicalizer $requestCanonicalizer,
        private readonly ResponseCanonicalizer $responseCanonicalizer,
        private readonly NonceStoreInterface $nonceStore,
        private readonly RateLimiterInterface $rateLimiter,
        private readonly ClockSkewValidator $clockSkewValidator,
        private readonly ClockInterface $clock,
        private readonly Config $config,
        private readonly ResponseFactoryInterface $responseFactory,
    ) {}

    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        $requestId = $request->getHeaderLine(SignatureHeaders::REQUEST_ID);
        $auth      = $this->authenticate($request, $requestId);

        if ($auth->rateLimited) {
            return $this->rateLimitedResponse();
        }

        if (!$auth->authenticated || $auth->peer === null || $auth->key === null) {
            return $this->unauthorizedResponse();
        }

        $authenticatedRequest = $request
            ->withAttribute('uphptime.peer', $auth->peer)
            ->withAttribute('uphptime.key_id', $auth->key->keyId);

        $response = $handler->handle($authenticatedRequest);

        return $this->signResponse($response, $requestId, $auth->key->secret);
    }

    private function authenticate(ServerRequestInterface $request, string $requestId): AuthResult
    {
        $keyId     = $request->getHeaderLine(SignatureHeaders::KEY_ID);
        $timestamp = $request->getHeaderLine(SignatureHeaders::TIMESTAMP);
        $nonce     = $request->getHeaderLine(SignatureHeaders::NONCE);
        $signature = $request->getHeaderLine(SignatureHeaders::SIGNATURE);

        if ($keyId === '' || $timestamp === '' || $nonce === '' || $requestId === '' || $signature === '' || !ctype_digit($timestamp)) {
            return AuthResult::unauthorized();
        }

        $lookup = $this->peerRepository->findByInboundKeyId($keyId);
        if ($lookup === null) {
            return AuthResult::unauthorized();
        }

        $now = $this->clock->now();
        if (!$this->clockSkewValidator->isWithinWindow($now, (int) $timestamp, $this->config->clockSkewSeconds)) {
            return AuthResult::unauthorized();
        }

        $canonical = $this->requestCanonicalizer->canonicalize(
            $request->getMethod(),
            $request->getUri()->getPath(),
            $keyId,
            $timestamp,
            $nonce,
            $requestId,
            (string) $request->getBody(),
        );

        if (!$this->signer->verify($canonical, $lookup['key']->secret, $signature)) {
            return AuthResult::unauthorized();
        }

        if (!$this->nonceStore->recordIfNew($keyId, $nonce, $now, $this->config->clockSkewSeconds)) {
            return AuthResult::unauthorized();
        }

        $limit = $lookup['peer']->rateLimitPerMinute ?? $this->config->defaultPeerRateLimitPerMinute;
        if (!$this->rateLimiter->allow($keyId, $now, $limit)) {
            return AuthResult::rateLimited();
        }

        return AuthResult::success($lookup['peer'], $lookup['key']);
    }

    /** @param array<string, mixed> $body */
    private function jsonResponse(int $status, array $body): ResponseInterface
    {
        $response = $this->responseFactory->createResponse($status);
        $response->getBody()->write((string) json_encode($body, JSON_THROW_ON_ERROR));

        return $response->withHeader('Content-Type', 'application/json');
    }

    private function rateLimitedResponse(): ResponseInterface
    {
        return $this->jsonResponse(429, ['error' => 'rate_limited'])->withHeader('Retry-After', '60');
    }

    private function signResponse(ResponseInterface $response, string $requestId, string $secret): ResponseInterface
    {
        $responseTimestamp  = (string) $this->clock->now()->getTimestamp();
        $canonical          = $this->responseCanonicalizer->canonicalize(
            $requestId,
            $responseTimestamp,
            $response->getStatusCode(),
            (string) $response->getBody(),
        );
        $signature = $this->signer->sign($canonical, $secret);

        return $response
            ->withHeader(SignatureHeaders::REQUEST_ID, $requestId)
            ->withHeader(SignatureHeaders::RESPONSE_TIMESTAMP, $responseTimestamp)
            ->withHeader(SignatureHeaders::SIGNATURE, $signature);
    }

    private function unauthorizedResponse(): ResponseInterface
    {
        return $this->jsonResponse(401, ['error' => 'unauthorized']);
    }
}

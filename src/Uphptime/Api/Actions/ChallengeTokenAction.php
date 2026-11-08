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

namespace Uphptime\Api\Actions;

use DateTimeInterface;
use Psr\Http\Message\ResponseFactoryInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Uphptime\Api\ActionInterface;
use Uphptime\Api\ChallengeToken\ChallengeTokenStoreInterface;
use Uphptime\Config\Config;
use Uphptime\Storage\SiteRepositoryInterface;
use Uphptime\Support\ClockInterface;

/**
 * Deliberately unauthenticated (result attestation Layer 1): exposes the current challenge token for
 * one site, so an admin's own web server config can relay it onto the monitored site itself (as a
 * response header or at /.well-known/uphptime) - the exact "small... endpoint that the owner's node
 * writes" ARCHITECTURE.md describes. Knowing the token proves nothing on its own; it's only useful
 * once it appears on the probed site, which is what the signed, verified result actually attests to.
 */
final class ChallengeTokenAction implements ActionInterface
{
    public function __construct(
        private readonly SiteRepositoryInterface $siteRepository,
        private readonly ChallengeTokenStoreInterface $tokenStore,
        private readonly ClockInterface $clock,
        private readonly Config $config,
        private readonly ResponseFactoryInterface $responseFactory,
    ) {}

    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        $siteId = $request->getAttribute('uphptime.site_id');
        $site   = is_string($siteId) ? $this->siteRepository->find($siteId) : null;

        if ($site === null || !$site->usesChallengeToken()) {
            return $this->jsonResponse(404, ['error' => 'not_found']);
        }

        $now   = $this->clock->now();
        $token = $this->tokenStore->current($siteId, $now) ?? $this->tokenStore->issue($siteId, $now, $this->config->challengeTokenTtlSeconds);

        return $this->jsonResponse(200, [
            'token' => $token->token,
            'expires_at' => $token->expiresAt->format(DateTimeInterface::ATOM),
        ]);
    }

    /** @param array<string, mixed> $body */
    private function jsonResponse(int $status, array $body): ResponseInterface
    {
        $response = $this->responseFactory->createResponse($status);
        $response->getBody()->write((string) json_encode($body, JSON_THROW_ON_ERROR));

        return $response->withHeader('Content-Type', 'application/json');
    }
}

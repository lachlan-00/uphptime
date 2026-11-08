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

namespace Uphptime\Api\Actions;

use DateTimeImmutable;
use Psr\Http\Message\ResponseFactoryInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Uphptime\Api\ActionInterface;
use Uphptime\Peer\Peer;
use Uphptime\Pinger\DelegatedCheckExecutorInterface;
use Uphptime\Site\ChallengeDelivery;
use Uphptime\Site\CheckType;
use Uphptime\Site\KeywordMode;
use Uphptime\Site\Site;
use Uphptime\Site\SiteRuntimeState;
use Uphptime\Site\SiteState;

/**
 * The one REST action this milestone defines: run one check for an ephemeral site description and
 * return a signed result. No site ID or owner identity is ever read - the pinger stays stateless.
 */
final class CheckSiteAction implements ActionInterface
{
    public function __construct(
        private readonly DelegatedCheckExecutorInterface $executor,
        private readonly int $pingerMaxTimeoutSeconds,
        private readonly ResponseFactoryInterface $responseFactory,
    ) {}

    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        $peer = $request->getAttribute('uphptime.peer');
        if (!$peer instanceof Peer) {
            return $this->jsonResponse(500, ['error' => 'missing_peer_attribute']);
        }

        $payload = json_decode((string) $request->getBody(), true);
        if (!is_array($payload)) {
            return $this->jsonResponse(422, ['error' => 'invalid_json']);
        }

        $site = $this->siteFromPayload($payload);
        if ($site === null) {
            return $this->jsonResponse(422, ['error' => 'invalid_payload']);
        }

        $requestedTimeout  = is_numeric($payload['timeout_seconds'] ?? null) ? (float) $payload['timeout_seconds'] : (float) $this->pingerMaxTimeoutSeconds;
        $timeoutSeconds    = min($requestedTimeout, (float) $this->pingerMaxTimeoutSeconds);

        $outcome = $this->executor->execute($site, $peer, $timeoutSeconds);

        return $this->jsonResponse(200, [
            'outcome' => $outcome->result->outcome->value,
            'http_status' => $outcome->result->httpStatus,
            'response_time_ms' => $outcome->result->responseTimeMs,
            'keyword_matched' => $outcome->result->keywordMatched,
            'error' => $outcome->result->error,
            'evidence' => $outcome->evidence,
            'attestation' => 'none',
            'served_from_cache' => $outcome->servedFromCache,
            'cache_age_seconds' => $outcome->cacheAgeSeconds,
        ]);
    }

    /** @param array<string, mixed> $body */
    private function jsonResponse(int $status, array $body): ResponseInterface
    {
        $response = $this->responseFactory->createResponse($status);
        $response->getBody()->write((string) json_encode($body, JSON_THROW_ON_ERROR));

        return $response->withHeader('Content-Type', 'application/json');
    }

    /** @param array<string, mixed> $payload */
    private function siteFromPayload(array $payload): ?Site
    {
        $checkType = CheckType::tryFrom((string) ($payload['check_type'] ?? ''));
        if ($checkType === null) {
            return null;
        }

        $url  = isset($payload['url']) && is_string($payload['url']) ? $payload['url'] : null;
        $host = isset($payload['host']) && is_string($payload['host']) ? $payload['host'] : null;
        $port = isset($payload['port']) && is_int($payload['port']) ? $payload['port'] : null;

        $keyword           = isset($payload['keyword']) && is_string($payload['keyword']) ? $payload['keyword'] : null;
        $keywordMode       = isset($payload['keyword_mode']) ? KeywordMode::tryFrom((string) $payload['keyword_mode']) : null;
        $challengeDelivery = isset($payload['challenge_delivery']) ? ChallengeDelivery::tryFrom((string) $payload['challenge_delivery']) : null;

        $valid = match ($checkType) {
            CheckType::Http => $url !== null,
            CheckType::HttpKeyword => $url !== null && $keyword !== null && $keywordMode !== null,
            CheckType::Tcp => $host !== null && $port !== null,
            CheckType::Icmp => $host !== null,
        };

        if (!$valid) {
            return null;
        }

        $now = new DateTimeImmutable();

        return new Site(
            id: 'delegated',
            name: 'delegated',
            url: $url,
            host: $host,
            port: $port,
            checkType: $checkType,
            keyword: $keyword,
            keywordMode: $keywordMode,
            checkIntervalSeconds: null,
            enabled: true,
            createdAt: $now,
            state: new SiteRuntimeState(SiteState::Unknown, 0, null, null, $now, null),
            challengeDelivery: $challengeDelivery,
        );
    }
}

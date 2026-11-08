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

use function DI\autowire;
use function DI\factory;

use Psr\Container\ContainerInterface;
use Psr\Http\Message\ResponseFactoryInterface;
use Slim\Psr7\Factory\ResponseFactory;
use Uphptime\Api\Actions\ChallengeTokenAction;
use Uphptime\Api\Actions\CheckSiteAction;
use Uphptime\Api\Actions\ReportOverviewAction;
use Uphptime\Api\ChallengeToken\ChallengeTokenStoreInterface;
use Uphptime\Api\ChallengeToken\JsonChallengeTokenStore;
use Uphptime\Api\Client\HttpPeerCheckClient;
use Uphptime\Api\Client\PeerCheckClientInterface;
use Uphptime\Api\Dispatcher;
use Uphptime\Api\HmacAuthMiddleware;
use Uphptime\Api\ReportApiKeyMiddleware;
use Uphptime\Api\Security\ClockSkewValidator;
use Uphptime\Api\Security\JsonNonceStore;
use Uphptime\Api\Security\JsonRateLimiter;
use Uphptime\Api\Security\NonceStoreInterface;
use Uphptime\Api\Security\RateLimiterInterface;
use Uphptime\Api\Signing\HmacSigner;
use Uphptime\Api\Signing\HmacSignerInterface;
use Uphptime\Api\Signing\RequestCanonicalizer;
use Uphptime\Api\Signing\ResponseCanonicalizer;
use Uphptime\Config\Config;
use Uphptime\Pinger\DelegatedCheckExecutorInterface;
use Uphptime\Support\AtomicWriter;

return [
    ChallengeTokenAction::class => autowire(),
    ChallengeTokenStoreInterface::class => factory(
        static fn(ContainerInterface $c): ChallengeTokenStoreInterface => new JsonChallengeTokenStore($c->get(AtomicWriter::class), $c->get('data.dir') . '/challenge_tokens'),
    ),
    CheckSiteAction::class => factory(static function (ContainerInterface $c): CheckSiteAction {
        return new CheckSiteAction(
            $c->get(DelegatedCheckExecutorInterface::class),
            $c->get(Config::class)->pingerMaxTimeoutSeconds,
            $c->get(ResponseFactoryInterface::class),
        );
    }),
    ClockSkewValidator::class => autowire(),
    Dispatcher::class => factory(static fn(ContainerInterface $c): Dispatcher => new Dispatcher([
        'peer.check_site' => $c->get(CheckSiteAction::class),
        'challenge.get_token' => $c->get(ChallengeTokenAction::class),
        'report.overview' => $c->get(ReportOverviewAction::class),
    ])),
    HmacAuthMiddleware::class => autowire(),
    HmacSignerInterface::class => autowire(HmacSigner::class),
    NonceStoreInterface::class => factory(
        static fn(ContainerInterface $c): NonceStoreInterface => new JsonNonceStore($c->get(AtomicWriter::class), $c->get('data.dir') . '/peer_runtime/nonces'),
    ),
    PeerCheckClientInterface::class => autowire(HttpPeerCheckClient::class),
    RateLimiterInterface::class => factory(
        static fn(ContainerInterface $c): RateLimiterInterface => new JsonRateLimiter($c->get(AtomicWriter::class), $c->get('data.dir') . '/peer_runtime/rate_limits'),
    ),
    ReportApiKeyMiddleware::class => autowire(),
    ReportOverviewAction::class => autowire(),
    RequestCanonicalizer::class => autowire(),
    ResponseCanonicalizer::class => autowire(),
    ResponseFactoryInterface::class => autowire(ResponseFactory::class),
];

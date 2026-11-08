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

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Slim\Factory\AppFactory;
use Slim\Routing\RouteCollectorProxy;
use Uphptime\Api\Dispatcher;
use Uphptime\Api\HmacAuthMiddleware;
use Uphptime\Api\ReportApiKeyMiddleware;
use Uphptime\Config\Config;
use Uphptime\DicBuilder;

require dirname(__DIR__) . '/vendor/autoload.php';

$root       = dirname(__DIR__);
$configPath = getenv('UPHPTIME_CONFIG_PATH');
if (!is_string($configPath) || $configPath === '') {
    $configPath = $root . '/config/node.json';
}

$container = (new DicBuilder())->build($configPath);
$config    = $container->get(Config::class);

if (!$config->pingerEnabled) {
    http_response_code(404);
    exit;
}

AppFactory::setContainer($container);
$app = AppFactory::create();

// Without error middleware, an unmatched route or thrown exception is an uncaught fatal (HTTP 500
// with no body) rather than a clean 4xx - a malformed/mistaken peer request must not crash the process.
$app->addErrorMiddleware(false, true, true);

$app->get('/', function (ServerRequestInterface $request, ResponseInterface $response): ResponseInterface {
    return $response;
});

// Slim's CallableResolver tries to bind $this (the container) onto a route closure, which fails
// for a `static` closure (it cannot be rebound) - route-level closures must stay non-static.
$app->group('/peer/v1', function (RouteCollectorProxy $group) use ($container): void {
    $group->post('/check-site', function (ServerRequestInterface $request) use ($container): ResponseInterface {
        return $container->get(Dispatcher::class)->dispatch('peer.check_site', $request);
    });
})->add($container->get(HmacAuthMiddleware::class));

// Deliberately outside the group above and unauthenticated (result attestation Layer 1): this only
// ever hands back a value that's about to be made public anyway, on the monitored site itself - see
// ChallengeTokenAction's own docblock.
$app->get('/challenge/v1/{site}', function (ServerRequestInterface $request, ResponseInterface $response, array $args) use ($container): ResponseInterface {
    return $container->get(Dispatcher::class)->dispatch('challenge.get_token', $request->withAttribute('uphptime.site_id', $args['site']));
});

// For a dashboard client, not a peer: a single bearer key (ReportApiKeyMiddleware), never HMAC, with
// its own CORS handling since the dashboard is a separate-origin static site. The middleware itself
// answers the OPTIONS preflight, so both verbs route through it.
$app->map(['GET', 'OPTIONS'], '/report/v1/overview', function (ServerRequestInterface $request) use ($container): ResponseInterface {
    return $container->get(Dispatcher::class)->dispatch('report.overview', $request);
})->add($container->get(ReportApiKeyMiddleware::class));

// Catch-all fallback: any request that didn't match a route above gets a clean 404 JSON response
// instead of Slim's HttpNotFoundException, which produces noisy PHP error log entries without the
// request URL.  Common triggers are browser visits, health-check probes, or misconfigured clients.
$app->map(['GET', 'POST', 'PUT', 'DELETE', 'PATCH', 'HEAD', 'OPTIONS'], '/{path:.+}', function (ServerRequestInterface $request, ResponseInterface $response): ResponseInterface {
    $body = json_encode(['error' => 'not_found', 'message' => 'Unknown endpoint: ' . $request->getMethod() . ' ' . $request->getUri()->getPath()], JSON_THROW_ON_ERROR);
    $response->getBody()->write($body);
    return $response->withStatus(404)->withHeader('Content-Type', 'application/json');
});

$app->run();

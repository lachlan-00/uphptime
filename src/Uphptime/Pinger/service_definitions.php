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

use function DI\factory;

use Psr\Container\ContainerInterface;
use Uphptime\Config\Config;
use Uphptime\Pinger\Cache\CacheRefresher;
use Uphptime\Pinger\Cache\CheckCacheRepositoryInterface;
use Uphptime\Pinger\Cache\JsonCheckCacheRepository;
use Uphptime\Pinger\CacheRefreshRunner;
use Uphptime\Pinger\CachingDelegatedCheckExecutor;
use Uphptime\Pinger\DelegatedCheckExecutor;
use Uphptime\Pinger\DelegatedCheckExecutorInterface;
use Uphptime\Pinger\DnsResolverInterface;
use Uphptime\Pinger\PhpDnsResolver;
use Uphptime\Pinger\PreferredIpSelector;
use Uphptime\Support\AtomicWriter;
use Uphptime\Support\ClockInterface;

return [
    CacheRefresher::class => factory(
        static fn(ContainerInterface $c): CacheRefresher => new CacheRefresher($c->get(AtomicWriter::class), $c->get('data.dir') . '/pinger_cache'),
    ),
    CacheRefreshRunner::class => factory(static function (ContainerInterface $c): CacheRefreshRunner {
        return new CacheRefreshRunner(
            $c->get(CheckCacheRepositoryInterface::class),
            $c->get(CacheRefresher::class),
            // Always the plain executor, never the caching decorator below - the cron IS a refresh,
            // not a request the cache should itself try to intercept.
            $c->get(DelegatedCheckExecutor::class),
            $c->get(ClockInterface::class),
            $c->get(Config::class),
        );
    }),
    CheckCacheRepositoryInterface::class => factory(
        static fn(ContainerInterface $c): CheckCacheRepositoryInterface => new JsonCheckCacheRepository($c->get(AtomicWriter::class), $c->get('data.dir') . '/pinger_cache'),
    ),
    DelegatedCheckExecutor::class => factory(static function (ContainerInterface $c): DelegatedCheckExecutor {
        $config = $c->get(Config::class);

        return new DelegatedCheckExecutor(
            $c->get(DnsResolverInterface::class),
            $config->maxRedirects,
            $config->responseSizeCapBytes,
            $config->httpUserAgent,
            $config->tcpPortAllowlist,
            $config->egressAllowedRanges,
            $c->get(PreferredIpSelector::class),
        );
    }),
    // Milestone 2's plain executor when PingerCacheEnabled is off, reproducing that milestone's
    // always-fresh behavior exactly; the caching decorator wraps it otherwise.
    DelegatedCheckExecutorInterface::class => factory(static function (ContainerInterface $c): DelegatedCheckExecutorInterface {
        $config = $c->get(Config::class);
        $plain  = $c->get(DelegatedCheckExecutor::class);

        if (!$config->pingerCacheEnabled) {
            return $plain;
        }

        return new CachingDelegatedCheckExecutor(
            $plain,
            $c->get(CacheRefresher::class),
            $c->get(CheckCacheRepositoryInterface::class),
            $c->get(ClockInterface::class),
            $config->pingerCacheTtlSeconds,
            $config->pingerCacheMaxTrackedTargets,
        );
    }),
    DnsResolverInterface::class => factory(static fn(): DnsResolverInterface => new PhpDnsResolver()),
];

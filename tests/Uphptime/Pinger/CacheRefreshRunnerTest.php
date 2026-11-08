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

namespace Uphptime\Tests\Pinger;

use DateTimeImmutable;
use PHPUnit\Framework\TestCase;
use Uphptime\Check\CheckResult;
use Uphptime\Config\Config;
use Uphptime\Pinger\Cache\CacheRefresher;
use Uphptime\Pinger\Cache\JsonCheckCacheRepository;
use Uphptime\Pinger\CacheRefreshRunner;
use Uphptime\Pinger\DelegatedCheckOutcome;
use Uphptime\Site\CheckType;
use Uphptime\Site\Site;
use Uphptime\Site\SiteRuntimeState;
use Uphptime\Site\SiteState;
use Uphptime\Support\AtomicWriter;
use Uphptime\Tests\Support\FakeClock;

final class CacheRefreshRunnerTest extends TestCase
{
    private string $directory;

    public function testEntryOutsideTheLeadWindowIsLeftAlone(): void
    {
        $clock     = new FakeClock();
        $refresher = new CacheRefresher(new AtomicWriter(), $this->directory);
        $inner     = new FakeDelegatedCheckExecutor([$this->passOutcome()]);

        $refresher->getOrRefresh($this->site('a.test'), $clock->now(), 60, static fn(): bool => false, fn(): DelegatedCheckOutcome => $inner->execute($this->site('a.test'), null, 5.0), $clock->now());

        $clock->advance('+10 seconds'); // TTL 60s, lead 10s: 50s left - outside the lead window
        $runner  = $this->runner($clock, $inner, ttlSeconds: 60, leadSeconds: 10, idleSeconds: 3600);
        $summary = $runner->run();

        self::assertSame(0, $summary->refreshed);
        self::assertSame(1, $inner->callCount, 'no second probe should have happened');
    }

    public function testEntryWithinTheLeadWindowIsRefreshedAndExtended(): void
    {
        $clock     = new FakeClock();
        $refresher = new CacheRefresher(new AtomicWriter(), $this->directory);
        $inner     = new FakeDelegatedCheckExecutor([$this->passOutcome(), $this->passOutcome()]);

        $refresher->getOrRefresh($this->site('a.test'), $clock->now(), 60, static fn(): bool => false, fn(): DelegatedCheckOutcome => $inner->execute($this->site('a.test'), null, 5.0), $clock->now());

        $clock->advance('+55 seconds'); // 5s left, inside the default 10s lead window
        $runner  = $this->runner($clock, $inner, ttlSeconds: 60, leadSeconds: 10, idleSeconds: 3600);
        $summary = $runner->run();

        self::assertSame(1, $summary->refreshed);
        self::assertSame(2, $inner->callCount);
    }

    public function testIdleEntryIsEvictedNotRefreshed(): void
    {
        $clock      = new FakeClock();
        $refresher  = new CacheRefresher(new AtomicWriter(), $this->directory);
        $repository = new JsonCheckCacheRepository(new AtomicWriter(), $this->directory);
        $inner      = new FakeDelegatedCheckExecutor([$this->passOutcome()]);

        $refresher->getOrRefresh($this->site('idle.test'), $clock->now(), 60, static fn(): bool => false, fn(): DelegatedCheckOutcome => $inner->execute($this->site('idle.test'), null, 5.0), $clock->now());

        $clock->advance('+2 hours');
        $runner  = $this->runner($clock, $inner, ttlSeconds: 60, leadSeconds: 10, idleSeconds: 3600);
        $summary = $runner->run();

        self::assertSame(1, $summary->evicted);
        self::assertSame(0, $summary->refreshed);
        self::assertSame([], $repository->all());
    }

    protected function setUp(): void
    {
        $this->directory = sys_get_temp_dir() . '/uphptime-cache-refresh-runner-' . bin2hex(random_bytes(4));
        mkdir($this->directory, 0775, true);
    }

    protected function tearDown(): void
    {
        foreach (glob($this->directory . '/*') ?: [] as $file) {
            @unlink($file);
        }
        @rmdir($this->directory);
    }

    private function passOutcome(): DelegatedCheckOutcome
    {
        return new DelegatedCheckOutcome(CheckResult::pass(200, 10), ['resolved_ip' => '93.184.216.34']);
    }

    private function runner(FakeClock $clock, FakeDelegatedCheckExecutor $inner, int $ttlSeconds, int $leadSeconds, int $idleSeconds): CacheRefreshRunner
    {
        $writer = new AtomicWriter();
        $config = new Config(
            dataDirectory: 'data',
            checkIntervalSeconds: 300,
            retentionDays: 90,
            requestTimeoutSeconds: 10,
            runBudgetSeconds: 240,
            staleAfterSeconds: 900,
            httpUserAgent: 'uphptime-test',
            timezone: 'UTC',
            pingerCacheTtlSeconds: $ttlSeconds,
            pingerCacheRefreshLeadSeconds: $leadSeconds,
            pingerCacheIdleEvictionSeconds: $idleSeconds,
        );

        return new CacheRefreshRunner(
            new JsonCheckCacheRepository($writer, $this->directory),
            new CacheRefresher($writer, $this->directory),
            $inner,
            $clock,
            $config,
        );
    }

    private function site(string $host): Site
    {
        $now = new DateTimeImmutable('2026-01-01T00:00:00+00:00');

        return new Site('s', 's', null, $host, 25, CheckType::Tcp, null, null, null, true, $now, new SiteRuntimeState(SiteState::Unknown, 0, null, null, $now, null));
    }
}

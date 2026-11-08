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

namespace Uphptime\Tests\Pinger\Cache;

use DateTimeImmutable;
use PHPUnit\Framework\TestCase;
use Uphptime\Check\CheckResult;
use Uphptime\Pinger\Cache\CacheRefresher;
use Uphptime\Pinger\Cache\JsonCheckCacheRepository;
use Uphptime\Pinger\DelegatedCheckOutcome;
use Uphptime\Site\CheckType;
use Uphptime\Site\Site;
use Uphptime\Site\SiteRuntimeState;
use Uphptime\Site\SiteState;
use Uphptime\Support\AtomicWriter;

final class JsonCheckCacheRepositoryTest extends TestCase
{
    private string $directory;

    public function testEmptyDirectoryBehavesAsEmptyRatherThanErroring(): void
    {
        $repository = new JsonCheckCacheRepository(new AtomicWriter(), $this->directory . '/not-yet-created');

        self::assertSame([], $repository->all());
        self::assertSame(0, $repository->countTracked());
        self::assertNull($repository->findLeastRecentlyRequested());
        self::assertNull($repository->find('missing'));
    }

    public function testFindLeastRecentlyRequestedReturnsTheOldestNotAnArbitraryOne(): void
    {
        $repository = new JsonCheckCacheRepository(new AtomicWriter(), $this->directory);
        $refresher  = new CacheRefresher(new AtomicWriter(), $this->directory);

        $this->seed($refresher, 'a.test', new DateTimeImmutable('2026-01-01T00:00:00+00:00'));
        $this->seed($refresher, 'b.test', new DateTimeImmutable('2026-01-01T00:05:00+00:00'));
        $this->seed($refresher, 'c.test', new DateTimeImmutable('2026-01-01T00:10:00+00:00'));

        self::assertSame(3, $repository->countTracked());

        $oldest = $repository->findLeastRecentlyRequested();
        self::assertNotNull($oldest);
        self::assertSame('a.test', $oldest->host);
    }

    public function testRoundTripsThroughFind(): void
    {
        $repository = new JsonCheckCacheRepository(new AtomicWriter(), $this->directory);
        $refresher  = new CacheRefresher(new AtomicWriter(), $this->directory);
        $now        = new DateTimeImmutable('2026-01-01T00:00:00+00:00');

        $written = $refresher->getOrRefresh(
            $this->site('tcp.test', 2525),
            $now,
            60,
            static fn(): bool => false,
            static fn(): DelegatedCheckOutcome => new DelegatedCheckOutcome(CheckResult::pass(null, 5), ['resolved_ip' => '203.0.113.5']),
            $now,
        );

        $found = $repository->find($written->fingerprint);

        self::assertNotNull($found);
        self::assertSame('tcp.test', $found->host);
        self::assertSame(2525, $found->port);
        self::assertSame('203.0.113.5', $found->evidence['resolved_ip'] ?? null);
    }

    protected function setUp(): void
    {
        $this->directory = sys_get_temp_dir() . '/uphptime-cache-repo-' . bin2hex(random_bytes(4));
        mkdir($this->directory, 0775, true);
    }

    protected function tearDown(): void
    {
        foreach (glob($this->directory . '/*') ?: [] as $file) {
            @unlink($file);
        }
        @rmdir($this->directory);
    }

    private function seed(CacheRefresher $refresher, string $host, DateTimeImmutable $lastRequestedAt): void
    {
        $refresher->getOrRefresh(
            $this->site($host, 25),
            $lastRequestedAt,
            60,
            static fn(): bool => false,
            static fn(): DelegatedCheckOutcome => new DelegatedCheckOutcome(CheckResult::pass(null, 5), ['resolved_ip' => '203.0.113.9']),
            $lastRequestedAt,
        );
    }

    private function site(string $host, int $port): Site
    {
        $now = new DateTimeImmutable('2026-01-01T00:00:00+00:00');

        return new Site('s', 's', null, $host, $port, CheckType::Tcp, null, null, null, true, $now, new SiteRuntimeState(SiteState::Unknown, 0, null, null, $now, null));
    }
}

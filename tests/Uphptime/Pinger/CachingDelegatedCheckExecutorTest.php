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
use Uphptime\Check\CheckOutcome;
use Uphptime\Check\CheckResult;
use Uphptime\Peer\Peer;
use Uphptime\Peer\PeerKey;
use Uphptime\Pinger\Cache\CacheRefresher;
use Uphptime\Pinger\Cache\JsonCheckCacheRepository;
use Uphptime\Pinger\CachingDelegatedCheckExecutor;
use Uphptime\Pinger\DelegatedCheckOutcome;
use Uphptime\Site\CheckType;
use Uphptime\Site\Site;
use Uphptime\Site\SiteRuntimeState;
use Uphptime\Site\SiteState;
use Uphptime\Support\AtomicWriter;
use Uphptime\Tests\Support\FakeClock;

final class CachingDelegatedCheckExecutorTest extends TestCase
{
    private string $directory;

    public function testExpiredEntryIsTreatedAsAMissAndReProbed(): void
    {
        $clock    = new FakeClock();
        $inner    = new FakeDelegatedCheckExecutor([$this->passOutcome(), $this->passOutcome()]);
        $executor = $this->executor($inner, $clock, ttlSeconds: 60);

        $executor->execute($this->site(), null, 5.0);
        $clock->advance('+61 seconds');
        $executor->execute($this->site(), null, 5.0);

        self::assertSame(2, $inner->callCount);
    }

    public function testImmediateSecondCallIsServedFromCacheWithoutCallingTheInnerExecutor(): void
    {
        $clock    = new FakeClock();
        $inner    = new FakeDelegatedCheckExecutor([$this->passOutcome()]);
        $executor = $this->executor($inner, $clock, ttlSeconds: 60);

        $first = $executor->execute($this->site(), null, 5.0);
        $clock->advance('+10 seconds');
        $second = $executor->execute($this->site(), null, 5.0);

        self::assertSame(1, $inner->callCount);
        self::assertFalse($first->servedFromCache);
        self::assertTrue($second->servedFromCache);
        self::assertSame(10, $second->cacheAgeSeconds);
    }

    // The security property this whole design is built around: a cache primed by an unrestricted
    // peer must not be handed to a different, more-restrictive peer whose own allowlist denies it.
    public function testMoreRestrictivePeerIsDeniedACacheHitAnUnrestrictedPeerPopulated(): void
    {
        $clock    = new FakeClock();
        $inner    = new FakeDelegatedCheckExecutor([$this->passOutcome('93.184.216.34')]);
        $executor = $this->executor($inner, $clock, ttlSeconds: 60);

        $unrestrictedPeer = $this->peer([]);
        $restrictedPeer   = $this->peer(['only-allowed.test']);

        $first  = $executor->execute($this->site(), $unrestrictedPeer, 5.0);
        $second = $executor->execute($this->site(), $restrictedPeer, 5.0);

        self::assertSame(CheckOutcome::Pass, $first->result->outcome);
        self::assertSame(1, $inner->callCount, 'only the first (allowed) request should have probed');
        self::assertSame(CheckOutcome::Error, $second->result->outcome, 'the restricted peer must not receive the cached result');
    }

    // Regression: two calls within the same wall-clock second must not be misread as "not from
    // cache" just because probedAt and now share the same integer timestamp - hit detection must
    // key off whether a probe actually ran, not off comparing timestamps.
    public function testTwoCallsWithinTheSameInstantStillDetectTheSecondAsACacheHit(): void
    {
        $clock    = new FakeClock();
        $inner    = new FakeDelegatedCheckExecutor([$this->passOutcome()]);
        $executor = $this->executor($inner, $clock, ttlSeconds: 60);

        $first  = $executor->execute($this->site(), null, 5.0);
        $second = $executor->execute($this->site(), null, 5.0);

        self::assertSame(1, $inner->callCount);
        self::assertFalse($first->servedFromCache);
        self::assertTrue($second->servedFromCache);
        self::assertSame(0, $second->cacheAgeSeconds);
    }

    protected function setUp(): void
    {
        $this->directory = sys_get_temp_dir() . '/uphptime-caching-executor-' . bin2hex(random_bytes(4));
        mkdir($this->directory, 0775, true);
    }

    protected function tearDown(): void
    {
        foreach (glob($this->directory . '/*') ?: [] as $file) {
            @unlink($file);
        }
        @rmdir($this->directory);
    }

    private function executor(FakeDelegatedCheckExecutor $inner, FakeClock $clock, int $ttlSeconds): CachingDelegatedCheckExecutor
    {
        $writer = new AtomicWriter();

        return new CachingDelegatedCheckExecutor(
            $inner,
            new CacheRefresher($writer, $this->directory),
            new JsonCheckCacheRepository($writer, $this->directory),
            $clock,
            $ttlSeconds,
            500,
        );
    }

    private function passOutcome(string $resolvedIp = '93.184.216.34'): DelegatedCheckOutcome
    {
        return new DelegatedCheckOutcome(CheckResult::pass(200, 10), ['resolved_ip' => $resolvedIp]);
    }

    /** @param string[] $targetAllowlist */
    private function peer(array $targetAllowlist): Peer
    {
        return new Peer('peer-a', 'Peer A', null, true, true, null, $targetAllowlist, [
            new PeerKey('k1', 'secret', true, true, true, new DateTimeImmutable('2026-01-01T00:00:00+00:00')),
        ]);
    }

    private function site(): Site
    {
        $now = new DateTimeImmutable('2026-01-01T00:00:00+00:00');

        return new Site('s', 's', 'http://example.test/', null, null, CheckType::Http, null, null, null, true, $now, new SiteRuntimeState(SiteState::Unknown, 0, null, null, $now, null));
    }
}

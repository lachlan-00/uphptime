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

namespace Uphptime\Pinger;

use DateTimeImmutable;
use Uphptime\Check\CheckResult;
use Uphptime\Peer\Peer;
use Uphptime\Pinger\Cache\CacheRefresher;
use Uphptime\Pinger\Cache\CheckCacheEntry;
use Uphptime\Pinger\Cache\CheckCacheRepositoryInterface;
use Uphptime\Site\CheckType;
use Uphptime\Site\Site;
use Uphptime\Support\ClockInterface;

/**
 * Decorates a real executor with a TTL'd result cache: identical requests for the same check within
 * the TTL reuse the last probe instead of repeating it. Always honest about it - evidence timestamps
 * stay the original probe's, and the response says served_from_cache/cache_age_seconds explicitly.
 */
final class CachingDelegatedCheckExecutor implements DelegatedCheckExecutorInterface
{
    public function __construct(
        private readonly DelegatedCheckExecutorInterface $inner,
        private readonly CacheRefresher $cacheRefresher,
        private readonly CheckCacheRepositoryInterface $repository,
        private readonly ClockInterface $clock,
        private readonly int $ttlSeconds,
        private readonly int $maxTrackedTargets,
    ) {}

    public function execute(Site $site, ?Peer $callingPeer, float $timeoutSeconds): DelegatedCheckOutcome
    {
        $now         = $this->clock->now();
        $fingerprint = CacheFingerprint::forSite($site);

        if ($this->repository->find($fingerprint) === null) {
            $this->evictIfAtCapacity();
        }

        $probedJustNow = false;
        $entry         = $this->cacheRefresher->getOrRefresh(
            $site,
            $now,
            $this->ttlSeconds,
            static fn(CheckCacheEntry $entry, DateTimeImmutable $now): bool => !$entry->isExpired($now),
            fn(): DelegatedCheckOutcome => $this->inner->execute($site, $callingPeer, $timeoutSeconds),
            $now,
            $probedJustNow,
        );

        if (!$this->isTargetStillAllowedForCaller($site, $callingPeer, $entry)) {
            return new DelegatedCheckOutcome(CheckResult::error('target_not_allowed_for_peer'), null);
        }

        $servedFromCache = !$probedJustNow;

        return new DelegatedCheckOutcome(
            $entry->result,
            $entry->evidence,
            $servedFromCache,
            $servedFromCache ? $entry->ageSeconds($now) : 0,
        );
    }

    private function evictIfAtCapacity(): void
    {
        if ($this->repository->countTracked() < $this->maxTrackedTargets) {
            return;
        }

        $victim = $this->repository->findLeastRecentlyRequested();
        if ($victim !== null) {
            $this->repository->delete($victim->fingerprint);
        }
    }

    // Re-checks the calling peer's own target allowlist against the resolved IP a cache hit is about
    // to serve - skipping the live probe also skips the allowlist check normally enforced inside it.
    private function isTargetStillAllowedForCaller(Site $site, ?Peer $callingPeer, CheckCacheEntry $entry): bool
    {
        if ($callingPeer === null || $callingPeer->targetAllowlist === []) {
            return true;
        }

        $resolvedIp = $entry->evidence['resolved_ip'] ?? null;
        if (!is_string($resolvedIp)) {
            return true;
        }

        $isHostBased = in_array($site->checkType, [CheckType::Tcp, CheckType::Icmp], true);
        $host        = $isHostBased ? $site->host : parse_url((string) $site->url, PHP_URL_HOST);
        if (!is_string($host)) {
            return true;
        }

        // Only isTargetAllowed() is used here, so the other three constructor args are irrelevant -
        // block-range and port decisions are deterministic and were already enforced when this entry
        // was written; only the per-peer allowlist can legitimately differ between two requesters.
        $allowlist = new DefaultEgressPolicy([], [], [], $callingPeer->targetAllowlist);

        return $allowlist->isTargetAllowed($host, $resolvedIp);
    }
}

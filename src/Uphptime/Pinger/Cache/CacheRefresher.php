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

namespace Uphptime\Pinger\Cache;

use DateTimeImmutable;
use Uphptime\Check\CheckOutcome;
use Uphptime\Pinger\CacheFingerprint;
use Uphptime\Pinger\DelegatedCheckOutcome;
use Uphptime\Site\Site;
use Uphptime\Support\AtomicWriter;

/**
 * The single place a probe-and-cache-write ever happens, used by both the reactive request path and
 * the proactive cron: holds one exclusive lock, per fingerprint, across the whole "decide, then maybe
 * probe" sequence, so a concurrent caller for the same target always waits for whichever refresh is
 * already running instead of starting a redundant one.
 */
final class CacheRefresher
{
    public function __construct(
        private readonly AtomicWriter $writer,
        private readonly string $baseDirectory,
    ) {}

    /**
     * @param callable(CheckCacheEntry, DateTimeImmutable): bool $isFreshCallback true = still fresh, no probe needed
     * @param callable(): DelegatedCheckOutcome $probeCallback
     * @param DateTimeImmutable|null $requestedAt non-null bumps last_requested_at to this value (a
     *        genuine request); null preserves whatever was already stored (a background cron refresh),
     *        so idle eviction tracks real requests, not the cron's own activity.
     * @param bool|null &$probedJustNow set to whether this call actually ran the probe callback - the
     *        caller's own notion of "served from cache" must key off this, not off comparing
     *        timestamps: two calls within the same wall-clock second (exactly the case this cache
     *        exists to optimize for) would otherwise look identical whether served fresh or cached.
     * @param-out bool $probedJustNow
     */
    public function getOrRefresh(
        Site $site,
        DateTimeImmutable $now,
        int $ttlSeconds,
        callable $isFreshCallback,
        callable $probeCallback,
        ?DateTimeImmutable $requestedAt = null,
        ?bool &$probedJustNow = null,
    ): CheckCacheEntry {
        $fingerprint   = CacheFingerprint::forSite($site);
        $path          = $this->baseDirectory . '/' . $fingerprint . '.json';
        $resolved      = null;
        $probedJustNow = false;

        $this->writer->readModifyWrite(
            $path,
            function (?string $current) use ($site, $fingerprint, $now, $ttlSeconds, $isFreshCallback, $probeCallback, $requestedAt, &$resolved, &$probedJustNow): ?string {
                $existing = $current !== null ? $this->decode($current) : null;

                if ($existing !== null && $isFreshCallback($existing, $now)) {
                    if ($requestedAt === null) {
                        $resolved = $existing;

                        return null;
                    }

                    $resolved = $existing->withLastRequestedAt($requestedAt);

                    return json_encode($resolved->toArray(), JSON_THROW_ON_ERROR) . "\n";
                }

                $probedJustNow = true;
                $outcome       = $probeCallback();

                // An Error can stem from this specific caller's own egress policy (its own target
                // allowlist, for instance), so it is never shared across callers - hand it back
                // without persisting it, leaving whatever was already cached untouched.
                if ($outcome->result->outcome === CheckOutcome::Error) {
                    $resolved = CheckCacheEntry::transient($fingerprint, $site, $outcome, $now);

                    return null;
                }

                $resolved = new CheckCacheEntry(
                    fingerprint: $fingerprint,
                    checkType: $site->checkType,
                    url: $site->url,
                    host: $site->host,
                    port: $site->port,
                    keyword: $site->keyword,
                    keywordMode: $site->keywordMode,
                    result: $outcome->result,
                    evidence: $outcome->evidence,
                    attestation: 'none',
                    probedAt: $now,
                    expiresAt: $now->modify(sprintf('+%d seconds', $ttlSeconds)),
                    lastRequestedAt: $requestedAt ?? ($existing !== null ? $existing->lastRequestedAt : $now),
                );

                return json_encode($resolved->toArray(), JSON_THROW_ON_ERROR) . "\n";
            },
        );

        /** @var CheckCacheEntry $resolved set on every path above before the callback returns */
        return $resolved;
    }

    private function decode(string $contents): ?CheckCacheEntry
    {
        $decoded = json_decode($contents, true);

        return is_array($decoded) ? CheckCacheEntry::fromArray($decoded) : null;
    }
}

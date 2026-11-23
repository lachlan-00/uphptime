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
 * The single place a probe-and-cache-write happens, shared by the reactive path and the proactive
 * cron: holds one lock per fingerprint, so a concurrent caller for the same target waits, never duplicates.
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
     * @param DateTimeImmutable|null $requestedAt non-null bumps last_requested_at; null preserves the stored value
     * @param bool|null &$probedJustNow true only if the probe callback actually ran this call
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

                // An Error can stem from this caller's own egress policy, so it's never shared - hand it back, don't persist it.
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

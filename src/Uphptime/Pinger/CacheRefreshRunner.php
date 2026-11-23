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
use Uphptime\Config\Config;
use Uphptime\Pinger\Cache\CacheRefresher;
use Uphptime\Pinger\Cache\CheckCacheEntry;
use Uphptime\Pinger\Cache\CheckCacheRepositoryInterface;
use Uphptime\Scheduling\RunBudget;
use Uphptime\Support\ClockInterface;

/**
 * The proactive cron: evicts idle-tracked targets, and re-probes the rest shortly before expiry so a
 * reactive request lands warm. Shares CacheRefresher with the reactive path, so it never duplicate-probes.
 */
final class CacheRefreshRunner
{
    public function __construct(
        private readonly CheckCacheRepositoryInterface $repository,
        private readonly CacheRefresher $cacheRefresher,
        private readonly DelegatedCheckExecutorInterface $plainExecutor,
        private readonly ClockInterface $clock,
        private readonly Config $config,
    ) {}

    public function run(?int $budgetSecondsOverride = null): CacheRefreshSummary
    {
        $startedAtMs = $this->nowMs();
        $now         = $this->clock->now();
        $budget      = new RunBudget($this->clock, $budgetSecondsOverride ?? $this->config->runBudgetSeconds);

        $refreshed = 0;
        $evicted   = 0;
        $skipped   = 0;

        foreach ($this->repository->all() as $entry) {
            if ($budget->isExhausted()) {
                $skipped++;
                continue;
            }

            if ($this->isIdle($entry, $now)) {
                $this->repository->delete($entry->fingerprint);
                $evicted++;
                continue;
            }

            if ($this->needsRefresh($entry, $now)) {
                $this->refresh($entry, $now);
                $refreshed++;
            }
        }

        return new CacheRefreshSummary($refreshed, $evicted, $skipped, $this->nowMs() - $startedAtMs);
    }

    private function isIdle(CheckCacheEntry $entry, DateTimeImmutable $now): bool
    {
        return $now->getTimestamp() - $entry->lastRequestedAt->getTimestamp() >= $this->config->pingerCacheIdleEvictionSeconds;
    }

    private function needsRefresh(CheckCacheEntry $entry, DateTimeImmutable $now): bool
    {
        $leadWindowStart = $entry->expiresAt->modify(sprintf('-%d seconds', $this->config->pingerCacheRefreshLeadSeconds));

        return $now >= $leadWindowStart;
    }

    private function nowMs(): int
    {
        return (int) round($this->clock->now()->format('U.u') * 1000);
    }

    // requestedAt stays null here so last_requested_at reflects genuine requests, never this cron's own activity.
    private function refresh(CheckCacheEntry $entry, DateTimeImmutable $now): void
    {
        $site = $entry->toSite();

        $this->cacheRefresher->getOrRefresh(
            $site,
            $now,
            $this->config->pingerCacheTtlSeconds,
            fn(CheckCacheEntry $current, DateTimeImmutable $now): bool => !$this->needsRefresh($current, $now),
            fn(): DelegatedCheckOutcome => $this->plainExecutor->execute($site, null, (float) $this->config->pingerMaxTimeoutSeconds),
        );
    }
}

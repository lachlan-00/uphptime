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

/**
 * Plain read/list/delete access to the tracked cache entries, used for eviction bookkeeping. The
 * locked probe-or-serve decision itself lives in CacheRefresher, not here - a "repository save" would
 * re-open the TOCTOU gap the whole cache design exists to close.
 */
interface CheckCacheRepositoryInterface
{
    /** @return CheckCacheEntry[] */
    public function all(): array;

    public function countTracked(): int;

    public function delete(string $fingerprint): void;

    public function find(string $fingerprint): ?CheckCacheEntry;

    public function findLeastRecentlyRequested(): ?CheckCacheEntry;
}

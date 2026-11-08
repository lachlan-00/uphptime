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

use Uphptime\Support\AtomicWriter;

/**
 * One file per fingerprint under data/pinger_cache/. Reads go through AtomicWriter's own shared lock
 * so a listing/read never sees a torn write from a concurrent CacheRefresher::getOrRefresh() call.
 */
final class JsonCheckCacheRepository implements CheckCacheRepositoryInterface
{
    public function __construct(
        private readonly AtomicWriter $writer,
        private readonly string $baseDirectory,
    ) {}

    /** @return CheckCacheEntry[] */
    public function all(): array
    {
        $entries = [];
        foreach ($this->fingerprints() as $fingerprint) {
            $entry = $this->find($fingerprint);
            if ($entry !== null) {
                $entries[] = $entry;
            }
        }

        return $entries;
    }

    public function countTracked(): int
    {
        return count($this->fingerprints());
    }

    public function delete(string $fingerprint): void
    {
        $path = $this->pathFor($fingerprint);
        @unlink($path);
        @unlink($path . '.lock');
    }

    public function find(string $fingerprint): ?CheckCacheEntry
    {
        $contents = $this->writer->read($this->pathFor($fingerprint));
        if ($contents === null) {
            return null;
        }

        $decoded = json_decode($contents, true);

        return is_array($decoded) ? CheckCacheEntry::fromArray($decoded) : null;
    }

    public function findLeastRecentlyRequested(): ?CheckCacheEntry
    {
        $oldest = null;
        foreach ($this->all() as $entry) {
            if ($oldest === null || $entry->lastRequestedAt < $oldest->lastRequestedAt) {
                $oldest = $entry;
            }
        }

        return $oldest;
    }

    /** @return string[] */
    private function fingerprints(): array
    {
        if (!is_dir($this->baseDirectory)) {
            return [];
        }

        $fingerprints = [];
        foreach (glob($this->baseDirectory . '/*.json') ?: [] as $path) {
            $fingerprints[] = basename($path, '.json');
        }

        return $fingerprints;
    }

    private function pathFor(string $fingerprint): string
    {
        return $this->baseDirectory . '/' . $fingerprint . '.json';
    }
}

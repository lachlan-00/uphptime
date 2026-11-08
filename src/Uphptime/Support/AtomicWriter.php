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

namespace Uphptime\Support;

use RuntimeException;

/**
 * Write-to-temp-then-atomic-rename under a dedicated sidecar lockfile (never flock on the data
 * file itself, since a rename over an open fd's path leaves the lock on the old, unlinked inode).
 */
final class AtomicWriter
{
    // Reads $path under the same sidecar lock write() uses, so a reader never sees a torn write.
    public function read(string $path): ?string
    {
        if (!is_file($path)) {
            return null;
        }

        return $this->withLock($path, LOCK_SH, function () use ($path): ?string {
            $contents = file_get_contents($path);

            return $contents === false ? null : $contents;
        });
    }

    // Reads and rewrites $path as one atomic unit under a single exclusive lock, so a concurrent
    // reader/writer can never interleave between the read and the write (the lost-update race that
    // separate read()-then-write() calls would allow). $modify receives the current contents (or
    // null if the file doesn't exist yet) and returns the new contents to persist, or null to leave
    // the file exactly as it was - the lock is still held for the whole decision, just nothing writes.
    public function readModifyWrite(string $path, callable $modify): void
    {
        $this->ensureDirectoryExists(dirname($path));

        $this->withLock($path, LOCK_EX, function () use ($path, $modify): void {
            $current = is_file($path) ? file_get_contents($path) : false;
            $new     = $modify($current === false ? null : $current);
            if ($new !== null) {
                $this->writeUnlocked($path, $new);
            }
        });
    }

    // Writes $contents to $path under an exclusive lock on "$path.lock".
    public function write(string $path, string $contents): void
    {
        $this->ensureDirectoryExists(dirname($path));

        $this->withLock($path, LOCK_EX, function () use ($path, $contents): void {
            $this->writeUnlocked($path, $contents);
        });
    }

    private function ensureDirectoryExists(string $directory): void
    {
        if (!is_dir($directory) && !mkdir($directory, 0775, true) && !is_dir($directory)) {
            throw new RuntimeException(sprintf('Unable to create directory: %s', $directory));
        }
    }

    /** @param int<0, 7> $lockMode */
    private function withLock(string $path, int $lockMode, callable $callback): mixed
    {
        $lockPath = $path . '.lock';
        $handle   = fopen($lockPath, 'c');
        if ($handle === false) {
            throw new RuntimeException(sprintf('Unable to open lock file: %s', $lockPath));
        }

        try {
            if (!flock($handle, $lockMode)) {
                throw new RuntimeException(sprintf('Unable to acquire lock: %s', $lockPath));
            }

            return $callback();
        } finally {
            flock($handle, LOCK_UN);
            fclose($handle);
        }
    }

    private function writeUnlocked(string $path, string $contents): void
    {
        $tempPath = $path . '.tmp.' . bin2hex(random_bytes(4));
        if (file_put_contents($tempPath, $contents) === false) {
            throw new RuntimeException(sprintf('Unable to write temp file: %s', $tempPath));
        }

        // Windows can refuse rename() onto an existing destination; retry once after removing it.
        if (@rename($tempPath, $path)) {
            return;
        }

        if (is_file($path) && @unlink($path) && @rename($tempPath, $path)) {
            return;
        }

        @unlink($tempPath);

        throw new RuntimeException(sprintf('Unable to rename temp file onto: %s', $path));
    }
}

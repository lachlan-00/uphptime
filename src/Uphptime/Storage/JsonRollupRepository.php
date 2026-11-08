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

namespace Uphptime\Storage;

use RuntimeException;
use Uphptime\History\DailyRollup;
use Uphptime\Support\AtomicWriter;

/**
 * One immutable rollup file per site per day, under data/rollups/<site-id>/<date>.json.
 */
final class JsonRollupRepository implements RollupRepositoryInterface
{
    public function __construct(
        private readonly AtomicWriter $writer,
        private readonly string $baseDirectory,
    ) {}

    public function datesWithRollup(string $siteId): array
    {
        $directory = $this->baseDirectory . DIRECTORY_SEPARATOR . $siteId;
        if (!is_dir($directory)) {
            return [];
        }

        $dates = [];
        foreach (scandir($directory) ?: [] as $entry) {
            if (preg_match('/^(\d{4}-\d{2}-\d{2})\.json$/', $entry, $matches) === 1) {
                $dates[] = $matches[1];
            }
        }

        sort($dates);

        return $dates;
    }

    public function delete(string $siteId, string $date): void
    {
        $path = $this->pathFor($siteId, $date);
        if (is_file($path)) {
            unlink($path);
        }
    }

    public function exists(string $siteId, string $date): bool
    {
        return is_file($this->pathFor($siteId, $date));
    }

    public function read(string $siteId, string $date): ?DailyRollup
    {
        $contents = $this->writer->read($this->pathFor($siteId, $date));
        if ($contents === null) {
            return null;
        }

        return DailyRollup::fromArray(json_decode($contents, true, 512, JSON_THROW_ON_ERROR));
    }

    // Writes a rollup exactly once; a rollup file is otherwise never modified after being closed.
    public function write(DailyRollup $rollup): void
    {
        $path      = $this->pathFor($rollup->siteId, $rollup->date);
        $directory = dirname($path);
        if (!is_dir($directory) && !mkdir($directory, 0775, true) && !is_dir($directory)) {
            throw new RuntimeException(sprintf('Unable to create directory: %s', $directory));
        }

        $this->writer->write($path, json_encode($rollup->toArray(), JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR) . "\n");
    }

    private function pathFor(string $siteId, string $date): string
    {
        return $this->baseDirectory . DIRECTORY_SEPARATOR . $siteId . DIRECTORY_SEPARATOR . $date . '.json';
    }
}

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
use Uphptime\History\HistoryRecord;

/**
 * One JSON Lines file per site per day, under data/history/<site-id>/<date>.jsonl.
 */
final class JsonHistoryRepository implements HistoryRepositoryInterface
{
    public function __construct(private readonly string $baseDirectory) {}

    // Appends one line under an exclusive flock; existing lines are never rewritten.
    public function append(string $siteId, HistoryRecord $record): void
    {
        $path = $this->pathFor($siteId, $record->ts->format('Y-m-d'));
        $this->ensureDirectoryExists(dirname($path));

        $handle = fopen($path, 'ab');
        if ($handle === false) {
            throw new RuntimeException(sprintf('Unable to open history file: %s', $path));
        }

        try {
            flock($handle, LOCK_EX);
            fwrite($handle, json_encode($record->toArray(), JSON_THROW_ON_ERROR) . "\n");
            fflush($handle);
            flock($handle, LOCK_UN);
        } finally {
            fclose($handle);
        }
    }

    public function datesWithHistory(string $siteId): array
    {
        $directory = $this->baseDirectory . DIRECTORY_SEPARATOR . $siteId;
        if (!is_dir($directory)) {
            return [];
        }

        $dates = [];
        foreach (scandir($directory) ?: [] as $entry) {
            if (preg_match('/^(\d{4}-\d{2}-\d{2})\.jsonl$/', $entry, $matches) === 1) {
                $dates[] = $matches[1];
            }
        }

        sort($dates);

        return $dates;
    }

    public function deleteDay(string $siteId, string $date): void
    {
        $path = $this->pathFor($siteId, $date);
        if (is_file($path)) {
            unlink($path);
        }
    }

    public function readDay(string $siteId, string $date): array
    {
        $path = $this->pathFor($siteId, $date);
        if (!is_file($path)) {
            return [];
        }

        $lines = file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
        if ($lines === false) {
            return [];
        }

        return array_map(
            static fn(string $line): HistoryRecord => HistoryRecord::fromArray(json_decode($line, true)),
            $lines,
        );
    }

    private function ensureDirectoryExists(string $directory): void
    {
        if (!is_dir($directory) && !mkdir($directory, 0775, true) && !is_dir($directory)) {
            throw new RuntimeException(sprintf('Unable to create directory: %s', $directory));
        }
    }

    private function pathFor(string $siteId, string $date): string
    {
        return $this->baseDirectory . DIRECTORY_SEPARATOR . $siteId . DIRECTORY_SEPARATOR . $date . '.jsonl';
    }
}

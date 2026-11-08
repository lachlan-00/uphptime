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

namespace Uphptime\Tests\Storage;

use Uphptime\History\HistoryRecord;
use Uphptime\Storage\HistoryRepositoryInterface;

/**
 * An in-memory HistoryRepositoryInterface double for tests that don't need real JSONL/flock behavior.
 */
final class InMemoryHistoryRepository implements HistoryRepositoryInterface
{
    /** @var array<string, array<string, HistoryRecord[]>> */
    private array $records = [];

    public function append(string $siteId, HistoryRecord $record): void
    {
        $this->records[$siteId][$record->ts->format('Y-m-d')][] = $record;
    }

    public function datesWithHistory(string $siteId): array
    {
        $dates = array_keys($this->records[$siteId] ?? []);
        sort($dates);

        return $dates;
    }

    public function deleteDay(string $siteId, string $date): void
    {
        unset($this->records[$siteId][$date]);
    }

    public function readDay(string $siteId, string $date): array
    {
        return $this->records[$siteId][$date] ?? [];
    }
}

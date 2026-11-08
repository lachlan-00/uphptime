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

use Uphptime\History\DailyRollup;
use Uphptime\Storage\RollupRepositoryInterface;

/**
 * An in-memory RollupRepositoryInterface double for tests that don't need real JSON/flock behavior.
 */
final class InMemoryRollupRepository implements RollupRepositoryInterface
{
    /** @var array<string, array<string, DailyRollup>> */
    private array $rollups = [];

    public function datesWithRollup(string $siteId): array
    {
        $dates = array_keys($this->rollups[$siteId] ?? []);
        sort($dates);

        return $dates;
    }

    public function delete(string $siteId, string $date): void
    {
        unset($this->rollups[$siteId][$date]);
    }

    public function exists(string $siteId, string $date): bool
    {
        return isset($this->rollups[$siteId][$date]);
    }

    public function read(string $siteId, string $date): ?DailyRollup
    {
        return $this->rollups[$siteId][$date] ?? null;
    }

    public function write(DailyRollup $rollup): void
    {
        $this->rollups[$rollup->siteId][$rollup->date] = $rollup;
    }
}

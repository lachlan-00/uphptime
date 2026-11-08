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

use Uphptime\History\DailyRollup;

/**
 * One immutable rollup file per site per day, kept indefinitely even after raw history is pruned.
 */
interface RollupRepositoryInterface
{
    /** @return string[] dates (oldest first) that have a rollup for $siteId */
    public function datesWithRollup(string $siteId): array;

    public function delete(string $siteId, string $date): void;

    public function exists(string $siteId, string $date): bool;

    public function read(string $siteId, string $date): ?DailyRollup;

    public function write(DailyRollup $rollup): void;
}

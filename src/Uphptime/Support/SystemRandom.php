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

/**
 * Real randomness via random_int(), the project's long-stable built-in of choice for this purpose.
 */
final class SystemRandom implements RandomInterface
{
    private const PROBABILITY_RESOLUTION = 1_000_000;

    public function pickIndex(int $count): int
    {
        return random_int(0, $count - 1);
    }

    public function probability(): float
    {
        return random_int(0, self::PROBABILITY_RESOLUTION) / self::PROBABILITY_RESOLUTION;
    }
}

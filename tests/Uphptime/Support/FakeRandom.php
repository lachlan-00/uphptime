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

namespace Uphptime\Tests\Support;

use Uphptime\Support\RandomInterface;

/**
 * A scripted RandomInterface double. Defaults to a probability of 1.0 (never below any sample rate,
 * so sampling never fires unless a test deliberately sets a lower probability) and always picks
 * index 0 (the first eligible candidate in list order), so a multi-peer scenario is fully
 * deterministic: excluding the previously-tried peer always surfaces the next one in save order.
 */
final class FakeRandom implements RandomInterface
{
    public function __construct(private readonly float $probability = 1.0) {}

    public function pickIndex(int $count): int
    {
        return 0;
    }

    public function probability(): float
    {
        return $this->probability;
    }
}

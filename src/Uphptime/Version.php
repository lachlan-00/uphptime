<?php

declare(strict_types=1);

/**
 * vim:set softtabstop=4 shiftwidth=4 expandtab:
 *
 * LICENSE: GNU Affero General Public License, version 3 (AGPL-3.0-or-later)
 * Copyright uPHPtime contributors, 2026
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

namespace Uphptime;

final class Version
{
    public const USER_AGENT = 'uPHPtime/' . self::VERSION . ' (+https://github.com/lachlan-00/uphptime)';
    public const VERSION    = '0.0.0';

    public static function userAgent(string $configured): string
    {
        if ($configured === '' || $configured === 'uPHPtime/0.1' || $configured === 'uPHPtime/0.1 (+https://github.com/lachlan-00/uphptime)') {
            return self::USER_AGENT;
        }

        return self::USER_AGENT . ' ' . $configured;
    }
}

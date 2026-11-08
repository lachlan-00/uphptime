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

namespace Uphptime\Storage\Pdo;

use DateTimeImmutable;
use DateTimeZone;

/**
 * One naive 'Y-m-d H:i:s.u' format shared by every repository, always UTC - MySQL's DATETIME has no
 * timezone concept at all, and PdoConnectionFactory sets PostgreSQL's session timezone to UTC, so the
 * same naive string round-trips correctly on both engines without any dialect-specific formatting.
 */
final class PdoTimestamp
{
    private const FORMAT = 'Y-m-d H:i:s.u';

    public static function format(DateTimeImmutable $value): string
    {
        return $value->format(self::FORMAT);
    }

    public static function parse(string $value): DateTimeImmutable
    {
        return new DateTimeImmutable($value, new DateTimeZone('UTC'));
    }
}

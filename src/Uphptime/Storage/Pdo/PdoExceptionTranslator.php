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

use PDOException;

/**
 * Turns a missing-table PDOException into StorageNotInstalledException; every other PDOException
 * (a real connectivity/syntax/permission failure) propagates unchanged.
 */
final class PdoExceptionTranslator
{
    private const MISSING_TABLE_SQLSTATES = ['42S02', '42P01'];

    public static function translate(PDOException $exception): never
    {
        $sqlstate = is_array($exception->errorInfo) ? ($exception->errorInfo[0] ?? null) : null;

        if (in_array($sqlstate, self::MISSING_TABLE_SQLSTATES, true)) {
            throw new StorageNotInstalledException(
                'Required tables are missing. Run "bin/uphptime storage:install" to create them.',
                previous: $exception,
            );
        }

        throw $exception;
    }
}

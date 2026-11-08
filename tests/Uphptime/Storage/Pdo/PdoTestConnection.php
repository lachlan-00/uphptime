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

namespace Uphptime\Tests\Storage\Pdo;

use PDO;
use PDOException;

/**
 * Fixed test-only credentials matching docs/examples/test-databases/docker-compose.yml, deliberately
 * not the application's own config - the app's config describes a real node's database, not these
 * throwaway test containers.
 */
final class PdoTestConnection
{
    // A failed attempt is cached per dialect for the rest of this process, so a whole test run only
    // pays the connection timeout once per dialect rather than once per test method's setUp().
    /** @var array<string, bool> */
    private static array $knownUnreachable = [];

    public static function attempt(string $dialect): ?PDO
    {
        if (self::$knownUnreachable[$dialect] ?? false) {
            return null;
        }

        $dsn = $dialect === 'pgsql'
            ? 'pgsql:host=127.0.0.1;port=5433;dbname=uphptime_test'
            : 'mysql:host=127.0.0.1;port=3307;dbname=uphptime_test;charset=utf8mb4';

        try {
            $pdo = new PDO($dsn, 'uphptime', 'uphptime', [PDO::ATTR_TIMEOUT => 2]);
            $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
            $pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);

            if ($dialect === 'pgsql') {
                $pdo->exec("SET TIME ZONE 'UTC'");
            } else {
                $pdo->setAttribute(PDO::ATTR_EMULATE_PREPARES, false);
                $pdo->exec('SET SESSION TRANSACTION ISOLATION LEVEL READ COMMITTED');
            }

            return $pdo;
        } catch (PDOException) {
            self::$knownUnreachable[$dialect] = true;

            return null;
        }
    }
}

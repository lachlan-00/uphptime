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

use PDO;
use Uphptime\Config\Config;

/**
 * Builds a connected PDO from config, resolving port 0 to the dialect's standard port so an existing
 * JSON-backend install needs zero new config keys. PostgreSQL's session timezone is pinned to UTC so
 * the same naive timestamp strings PdoTimestamp writes round-trip correctly on both engines.
 */
final class PdoConnectionFactory
{
    public function create(Config $config): PDO
    {
        $isPgsql = $config->storageBackend === 'pgsql';
        $port    = $config->sqlPort !== 0 ? $config->sqlPort : ($isPgsql ? 5432 : 3306);

        $dsn = $isPgsql
            ? sprintf('pgsql:host=%s;port=%d;dbname=%s', $config->sqlHost, $port, $config->sqlDatabase)
            : sprintf('mysql:host=%s;port=%d;dbname=%s;charset=utf8mb4', $config->sqlHost, $port, $config->sqlDatabase);

        $pdo = new PDO($dsn, $config->sqlUsername, $config->sqlPassword);
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);

        if ($isPgsql) {
            $pdo->exec("SET TIME ZONE 'UTC'");
        } else {
            $pdo->setAttribute(PDO::ATTR_EMULATE_PREPARES, false);
            // MySQL defaults to REPEATABLE READ, whose gap locks can deadlock an FK child-table upsert (pgsql defaults to this already).
            $pdo->exec('SET SESSION TRANSACTION ISOLATION LEVEL READ COMMITTED');
        }

        return $pdo;
    }
}

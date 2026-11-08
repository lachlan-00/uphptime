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
use Uphptime\Storage\Pdo\MySqlDialect;
use Uphptime\Storage\Pdo\PostgreSqlDialect;
use Uphptime\Storage\Pdo\SqlDialect;

/**
 * Schema install/truncate shared by every Pdo*RepositoryTestCase - attempt() returns null (the
 * container isn't reachable) rather than throwing, so a caller can markTestSkipped() instead of
 * failing the suite.
 */
trait PdoRepositoryTestHelpers
{
    protected function connectOrSkip(string $dialectName): PDO
    {
        $pdo = PdoTestConnection::attempt($dialectName);
        if ($pdo === null) {
            self::markTestSkipped(sprintf(
                '%s test container not reachable - run `docker compose -f docs/examples/test-databases/docker-compose.yml up -d` to enable this test.',
                strtoupper($dialectName),
            ));
        }

        $this->installSchema($pdo, $dialectName);
        $this->truncateAll($pdo);

        return $pdo;
    }

    protected function dialectFor(string $dialectName): SqlDialect
    {
        return $dialectName === 'pgsql' ? new PostgreSqlDialect() : new MySqlDialect();
    }

    private function installSchema(PDO $pdo, string $dialectName): void
    {
        $dialect  = $dialectName === 'pgsql' ? 'pgsql' : 'mysql';
        $path     = dirname(__DIR__, 4) . '/resources/sql/' . $dialect . '/schema.sql';
        $contents = (string) file_get_contents($path);

        foreach (array_filter(array_map('trim', explode(";\n", $contents))) as $statement) {
            $pdo->exec($statement);
        }
    }

    private function truncateAll(PDO $pdo): void
    {
        foreach (['peer_target_allowlist', 'peer_keys', 'peers', 'history', 'rollups', 'sites', 'peer_reputation', 'schema_version'] as $table) {
            $pdo->exec('DELETE FROM ' . $table);
        }
    }
}

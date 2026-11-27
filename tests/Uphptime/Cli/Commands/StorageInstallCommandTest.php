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

namespace Uphptime\Tests\Cli\Commands;

use PHPUnit\Framework\TestCase;
use Uphptime\Cli\Commands\StorageInstallCommand;
use Uphptime\Config\Config;
use Uphptime\Storage\Pdo\PdoConnectionFactory;
use Uphptime\Storage\Pdo\SchemaMigrator;
use Uphptime\Tests\Cli\FakeConsoleOutput;
use Uphptime\Tests\Storage\Pdo\PdoTestConnection;

final class StorageInstallCommandTest extends TestCase
{
    public function testJsonBackendIsANoOpThatNeverConnects(): void
    {
        $config  = $this->config('json');
        $output  = new FakeConsoleOutput();
        $command = new StorageInstallCommand($config, new PdoConnectionFactory(), new SchemaMigrator('json'), $output);

        self::assertSame(0, $command->run([]));

        $decoded = $output->lastLineDecoded();
        self::assertIsArray($decoded);
        self::assertSame('skipped', $decoded['status']);
    }

    public function testMysqlInstallIsIdempotent(): void
    {
        $this->assertInstallsAndRepeats('mysql');
    }

    public function testPostgresInstallIsIdempotent(): void
    {
        $this->assertInstallsAndRepeats('pgsql');
    }

    private function assertInstallsAndRepeats(string $dialect): void
    {
        $pdo = PdoTestConnection::attempt($dialect);
        if (!$pdo instanceof \PDO) {
            self::markTestSkipped(sprintf(
                '%s test container not reachable - run `docker compose -f docs/examples/test-databases/docker-compose.yml up -d` to enable this test.',
                strtoupper($dialect),
            ));
        }

        PdoTestConnection::dropAllTables($pdo, $dialect);

        $command = new StorageInstallCommand($this->config($dialect), new PdoConnectionFactory(), new SchemaMigrator($dialect), new FakeConsoleOutput());

        self::assertSame(0, $command->run([]));

        // The "sites" table now exists - a query against it no longer throws.
        $statement = $pdo->query('SELECT COUNT(*) AS total FROM sites');
        self::assertNotFalse($statement);

        // Second run must also succeed - every statement is idempotent.
        self::assertSame(0, $command->run([]));

        self::assertSame(SchemaMigrator::CURRENT_VERSION, (new SchemaMigrator($dialect))->installedVersion($pdo));

        // A database from before versioning (no recorded version, older columns) is upgraded without losing rows.
        $pdo->exec('DELETE FROM schema_version');
        $pdo->exec('ALTER TABLE rollups DROP COLUMN checks_confirmed_down');
        $pdo->exec("INSERT INTO history (site_id, ts, check_type, source, outcome, state_before, state_after) VALUES ('site-1', '2026-01-01 00:00:00', 'http', 'self', 'pass', 'unknown', 'up')");
        $pdo->exec('ALTER TABLE history DROP COLUMN peer_id');
        $pdo->exec('ALTER TABLE history DROP COLUMN peer_label');
        self::assertSame(0, $command->run([]));
        self::assertNotFalse($pdo->query('SELECT peer_id, peer_label FROM history'));
        self::assertNotFalse($pdo->query('SELECT checks_confirmed_down FROM rollups'));
        self::assertSame(SchemaMigrator::CURRENT_VERSION, (new SchemaMigrator($dialect))->installedVersion($pdo));
        self::assertSame(1, (int) $pdo->query('SELECT COUNT(*) FROM history')->fetchColumn());
        self::assertSame(0, $command->run([]));
    }

    private function config(string $backend): Config
    {
        $port = $backend === 'pgsql' ? 5433 : 3307;

        return new Config(
            dataDirectory: 'data',
            checkIntervalSeconds: 300,
            retentionDays: 90,
            requestTimeoutSeconds: 10,
            runBudgetSeconds: 240,
            staleAfterSeconds: 900,
            httpUserAgent: 'uphptime-test',
            timezone: 'UTC',
            storageBackend: $backend,
            sqlHost: '127.0.0.1',
            sqlPort: $port,
            sqlDatabase: 'uphptime_test',
            sqlUsername: 'uphptime',
            sqlPassword: 'uphptime',
        );
    }
}

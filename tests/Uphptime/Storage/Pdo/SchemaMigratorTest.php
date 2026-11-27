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
use PHPUnit\Framework\TestCase;
use Uphptime\Storage\Pdo\SchemaMigrator;
use Uphptime\Storage\Pdo\SchemaVersionException;

final class SchemaMigratorTest extends TestCase
{
    public function testMysqlFreshDatabaseIsStampedAtTheCurrentVersion(): void
    {
        $this->assertFreshDatabaseIsStamped('mysql');
    }

    public function testMysqlUnversionedDatabaseIsUpgraded(): void
    {
        $this->assertUnversionedDatabaseIsUpgraded('mysql');
    }

    public function testMysqlUpgradeInstallsMissingTablesColumnsAndIndexes(): void
    {
        $this->assertMissingItemsAreInstalled('mysql');
    }

    public function testMysqlVersionMismatchIsRefused(): void
    {
        $this->assertMismatchIsRefused('mysql');
    }

    public function testPostgresFreshDatabaseIsStampedAtTheCurrentVersion(): void
    {
        $this->assertFreshDatabaseIsStamped('pgsql');
    }

    public function testPostgresUnversionedDatabaseIsUpgraded(): void
    {
        $this->assertUnversionedDatabaseIsUpgraded('pgsql');
    }

    public function testPostgresUpgradeInstallsMissingTablesColumnsAndIndexes(): void
    {
        $this->assertMissingItemsAreInstalled('pgsql');
    }

    public function testPostgresVersionMismatchIsRefused(): void
    {
        $this->assertMismatchIsRefused('pgsql');
    }

    private function assertFreshDatabaseIsStamped(string $dialect): void
    {
        $pdo      = $this->freshDatabase($dialect);
        $migrator = new SchemaMigrator($dialect);

        self::assertSame(0, $migrator->installedVersion($pdo));
        $result = $migrator->upgrade($pdo);
        self::assertSame([0, SchemaMigrator::CURRENT_VERSION], [$result['from'], $result['to']]);
        self::assertContains('table sites', $result['added']);
        self::assertSame(SchemaMigrator::CURRENT_VERSION, $migrator->installedVersion($pdo));
        $migrator->assertCurrent($pdo);

        $again = $migrator->upgrade($pdo);
        self::assertSame([SchemaMigrator::CURRENT_VERSION, SchemaMigrator::CURRENT_VERSION], [$again['from'], $again['to']]);
        self::assertSame([], $again['added'], 'a complete database has nothing to install');
    }

    private function assertMismatchIsRefused(string $dialect): void
    {
        $pdo      = $this->freshDatabase($dialect);
        $migrator = new SchemaMigrator($dialect);
        $migrator->upgrade($pdo);

        $pdo->exec('DELETE FROM schema_version');
        $pdo->exec('INSERT INTO schema_version (version) VALUES (' . (SchemaMigrator::CURRENT_VERSION + 1) . ')');

        $this->expectException(SchemaVersionException::class);
        $migrator->upgrade($pdo);
    }

    private function assertMissingItemsAreInstalled(string $dialect): void
    {
        $pdo      = $this->freshDatabase($dialect);
        $migrator = new SchemaMigrator($dialect);
        $migrator->upgrade($pdo);
        self::assertSame(['missing_tables' => [], 'missing_columns' => [], 'missing_indexes' => []], $migrator->problems($pdo));

        // A versioned database that has lost a table, a column and an index.
        $pdo->exec('DROP TABLE site_groups');
        $pdo->exec('ALTER TABLE rollups DROP COLUMN checks_confirmed_down');
        $pdo->exec($dialect === 'pgsql' ? 'DROP INDEX idx_history_site_ts' : 'DROP INDEX idx_history_site_ts ON history');

        $problems = $migrator->problems($pdo);
        self::assertSame(['site_groups'], $problems['missing_tables']);
        self::assertSame(['rollups' => ['checks_confirmed_down']], $problems['missing_columns']);
        self::assertSame(['history' => ['idx_history_site_ts']], $problems['missing_indexes']);

        $result = $migrator->upgrade($pdo);

        self::assertEqualsCanonicalizing(['table site_groups', 'column rollups.checks_confirmed_down', 'index idx_history_site_ts on history'], $result['added']);
        self::assertSame(['missing_tables' => [], 'missing_columns' => [], 'missing_indexes' => []], $migrator->problems($pdo));
        $migrator->assertCurrent($pdo);
    }

    private function assertUnversionedDatabaseIsUpgraded(string $dialect): void
    {
        $pdo      = $this->freshDatabase($dialect);
        $migrator = new SchemaMigrator($dialect);
        $migrator->upgrade($pdo);

        $pdo->exec('DELETE FROM schema_version');
        $pdo->exec('ALTER TABLE rollups DROP COLUMN checks_confirmed_down');
        $pdo->exec('ALTER TABLE history DROP COLUMN peer_id');
        $pdo->exec('ALTER TABLE history DROP COLUMN peer_label');

        try {
            $migrator->assertCurrent($pdo);
            self::fail('an unversioned database must be refused until upgraded');
        } catch (SchemaVersionException) {
            // expected - the guard points the admin at storage:upgrade.
        }

        $result = $migrator->upgrade($pdo);
        self::assertSame([0, SchemaMigrator::CURRENT_VERSION], [$result['from'], $result['to']]);
        self::assertContains('column rollups.checks_confirmed_down', $result['added']);
        self::assertNotFalse($pdo->query('SELECT checks_confirmed_down FROM rollups'));
        self::assertNotFalse($pdo->query('SELECT peer_id, peer_label FROM history'));
        $migrator->assertCurrent($pdo);
    }

    private function freshDatabase(string $dialect): PDO
    {
        $pdo = PdoTestConnection::attempt($dialect);
        if (!$pdo instanceof PDO) {
            self::markTestSkipped(sprintf(
                '%s test container not reachable - run `docker compose -f docs/examples/test-databases/docker-compose.yml up -d` to enable this test.',
                strtoupper($dialect),
            ));
        }

        PdoTestConnection::dropAllTables($pdo, $dialect);

        return $pdo;
    }
}

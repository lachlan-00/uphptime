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

use PDO;
use PHPUnit\Framework\TestCase;
use Uphptime\Cli\Commands\StorageCheckCommand;
use Uphptime\Config\Config;
use Uphptime\Storage\Pdo\PdoConnectionFactory;
use Uphptime\Storage\Pdo\SchemaMigrator;
use Uphptime\Tests\Cli\FakeConsoleOutput;
use Uphptime\Tests\Storage\Pdo\PdoTestConnection;

final class StorageCheckCommandTest extends TestCase
{
    public function testJsonBackendIsANoOp(): void
    {
        $output  = new FakeConsoleOutput();
        $command = new StorageCheckCommand(new Config('data', 300, 90, 10, 240, 900, 'uphptime-test', 'UTC'), new PdoConnectionFactory(), new SchemaMigrator('json'), $output);

        self::assertSame(0, $command->run([]));
        self::assertSame('skipped', $output->lastLineDecoded()['status']);
    }

    public function testMysqlReportsMissingItemsThenOkAfterUpgrade(): void
    {
        $this->assertReportsMissingThenOk('mysql');
    }

    public function testPostgresReportsMissingItemsThenOkAfterUpgrade(): void
    {
        $this->assertReportsMissingThenOk('pgsql');
    }

    private function assertReportsMissingThenOk(string $dialect): void
    {
        $pdo = PdoTestConnection::attempt($dialect);
        if (!$pdo instanceof PDO) {
            self::markTestSkipped(sprintf(
                '%s test container not reachable - run `docker compose -f docs/examples/test-databases/docker-compose.yml up -d` to enable this test.',
                strtoupper($dialect),
            ));
        }

        PdoTestConnection::dropAllTables($pdo, $dialect);
        $migrator = new SchemaMigrator($dialect);
        $output   = new FakeConsoleOutput();
        $command  = new StorageCheckCommand($this->config($dialect), new PdoConnectionFactory(), $migrator, $output);

        self::assertSame(1, $command->run([]), 'an empty database is not valid');
        $report = $output->lastLineDecoded();
        self::assertSame('invalid', $report['status']);
        self::assertContains('sites', $report['missing_tables']);

        $migrator->upgrade($pdo);

        self::assertSame(0, $command->run([]));
        self::assertSame('ok', $output->lastLineDecoded()['status']);
    }

    private function config(string $backend): Config
    {
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
            sqlPort: $backend === 'pgsql' ? 5433 : 3307,
            sqlDatabase: 'uphptime_test',
            sqlUsername: 'uphptime',
            sqlPassword: 'uphptime',
        );
    }
}

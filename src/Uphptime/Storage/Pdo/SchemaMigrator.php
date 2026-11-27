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
use PDOException;
use Throwable;

/**
 * Creates the SQL schema, applies numbered upgrades and installs anything missing (tables, columns, indexes) by
 * comparing the database with schema.sql, recording the version in `schema_version`. A database with no recorded
 * version is treated as version 0; a database with none of the application tables is stamped at the current version.
 */
final class SchemaMigrator
{
    // Bump this and add a migrate<N>() method plus an entry in migrate() for every schema change that needs more than adding a column or index.
    public const CURRENT_VERSION = 1;

    private const MISSING_TABLE_SQLSTATES = ['42S02', '42P01'];

    private readonly SchemaDefinition $definition;

    public function __construct(private readonly string $backend)
    {
        $this->definition = new SchemaDefinition($backend);
    }

    // Refuses a database that is not exactly at the version this code expects.
    public function assertCurrent(PDO $pdo): void
    {
        $installed = $this->installedVersion($pdo);

        if ($installed > self::CURRENT_VERSION) {
            throw new SchemaVersionException(sprintf('Database schema version %d is newer than this application supports (%d). Update the application.', $installed, self::CURRENT_VERSION));
        }

        if ($installed < self::CURRENT_VERSION) {
            throw new SchemaVersionException(sprintf('Database schema version %d is older than the required version %d. Run "bin/uphptime storage:upgrade" before continuing.', $installed, self::CURRENT_VERSION));
        }
    }

    // 0 when the version table is missing or empty.
    public function installedVersion(PDO $pdo): int
    {
        try {
            $statement = $pdo->query('SELECT MAX(version) FROM schema_version');
        } catch (PDOException $pdoException) {
            $sqlstate = is_array($pdoException->errorInfo) ? ($pdoException->errorInfo[0] ?? null) : null;
            if (in_array($sqlstate, self::MISSING_TABLE_SQLSTATES, true)) {
                return 0;
            }

            throw $pdoException;
        }

        return $statement === false ? 0 : (int) $statement->fetchColumn();
    }

    /**
     * Read-only comparison of the database with schema.sql.
     *
     * @return array{missing_tables: string[], missing_columns: array<string, string[]>, missing_indexes: array<string, string[]>}
     */
    public function problems(PDO $pdo): array
    {
        $existing = $this->existingTables($pdo);
        $problems = ['missing_tables' => [], 'missing_columns' => [], 'missing_indexes' => []];

        foreach ($this->definition->tables() as $table => $expected) {
            if (!in_array($table, $existing, true)) {
                $problems['missing_tables'][] = $table;

                continue;
            }

            $missingColumns = array_values(array_diff(array_keys($expected['columns']), $this->columns($pdo, $table)));
            if ($missingColumns !== []) {
                $problems['missing_columns'][$table] = $missingColumns;
            }

            $missingIndexes = array_values(array_diff(array_keys($expected['indexes']), $this->indexes($pdo, $table)));
            if ($missingIndexes !== []) {
                $problems['missing_indexes'][$table] = $missingIndexes;
            }
        }

        return $problems;
    }

    /** @return array{from: int, to: int, added: string[]} the version before and after this run, and every table, column and index it installed */
    public function upgrade(PDO $pdo): array
    {
        $tables = array_keys($this->definition->tables());
        $before = $this->existingTables($pdo);
        $fresh  = array_diff(array_intersect($tables, $before), ['schema_version']) === [];
        $from   = $this->installedVersion($pdo);

        if ($from > self::CURRENT_VERSION) {
            throw new SchemaVersionException(sprintf('Database schema version %d is newer than this application supports (%d). Update the application.', $from, self::CURRENT_VERSION));
        }

        $added = $this->describe($this->problems($pdo));

        foreach ($this->definition->statements() as $statement) {
            $pdo->exec($statement);
        }

        if (!$fresh) {
            $this->runMigrations($pdo, $from);
        }

        $this->installMissing($pdo);

        $remaining = $this->problems($pdo);
        if ($remaining['missing_tables'] !== [] || $remaining['missing_columns'] !== [] || $remaining['missing_indexes'] !== []) {
            throw new SchemaVersionException('Database schema is still incomplete after upgrade: ' . json_encode($remaining, JSON_THROW_ON_ERROR));
        }

        if ($fresh) {
            $this->stamp($pdo, self::CURRENT_VERSION);
        }

        return ['from' => $fresh ? 0 : $from, 'to' => self::CURRENT_VERSION, 'added' => $added];
    }

    /** @return string[] */
    private function columns(PDO $pdo, string $table): array
    {
        $statement = $pdo->prepare(sprintf('SELECT column_name FROM information_schema.columns WHERE table_schema = %s AND table_name = ?', $this->currentSchema()));
        $statement->execute([$table]);

        return array_map(strval(...), $statement->fetchAll(PDO::FETCH_COLUMN));
    }

    private function currentSchema(): string
    {
        return $this->backend === 'pgsql' ? 'current_schema()' : 'DATABASE()';
    }

    /**
     * @param array{missing_tables: string[], missing_columns: array<string, string[]>, missing_indexes: array<string, string[]>} $problems
     * @return string[]
     */
    private function describe(array $problems): array
    {
        $described = array_map(static fn(string $table): string => 'table ' . $table, $problems['missing_tables']);

        foreach ($problems['missing_columns'] as $table => $columns) {
            foreach ($columns as $column) {
                $described[] = sprintf('column %s.%s', $table, $column);
            }
        }

        foreach ($problems['missing_indexes'] as $table => $indexes) {
            foreach ($indexes as $index) {
                $described[] = sprintf('index %s on %s', $index, $table);
            }
        }

        return $described;
    }

    private function execute(PDO $pdo, string $statement, string $what): void
    {
        try {
            $pdo->exec($statement);
        } catch (PDOException $pdoException) {
            throw new SchemaVersionException(sprintf('Could not install missing %s: %s', $what, $pdoException->getMessage()), previous: $pdoException);
        }
    }

    /** @return string[] */
    private function existingTables(PDO $pdo): array
    {
        $statement = $pdo->query(sprintf('SELECT table_name FROM information_schema.tables WHERE table_schema = %s', $this->currentSchema()));

        return $statement === false ? [] : array_map(strval(...), $statement->fetchAll(PDO::FETCH_COLUMN));
    }

    /** @return string[] */
    private function indexes(PDO $pdo, string $table): array
    {
        $sql = $this->backend === 'pgsql'
            ? 'SELECT indexname FROM pg_indexes WHERE schemaname = current_schema() AND tablename = ?'
            : 'SELECT DISTINCT index_name FROM information_schema.statistics WHERE table_schema = DATABASE() AND table_name = ?';

        $statement = $pdo->prepare($sql);
        $statement->execute([$table]);

        return array_map(strval(...), $statement->fetchAll(PDO::FETCH_COLUMN));
    }

    private function installMissing(PDO $pdo): void
    {
        foreach ($this->definition->tables() as $table => $expected) {
            $columns = $this->columns($pdo, $table);

            foreach ($expected['columns'] as $column => $definition) {
                if (!in_array($column, $columns, true)) {
                    $this->execute($pdo, sprintf('ALTER TABLE %s ADD COLUMN %s', $table, $definition), sprintf('column %s.%s', $table, $column));
                }
            }

            $indexes = $this->indexes($pdo, $table);

            foreach ($expected['indexes'] as $index => $definition) {
                if (!in_array($index, $indexes, true)) {
                    $statement = str_starts_with($definition, 'CREATE') ? $definition : sprintf('ALTER TABLE %s ADD %s', $table, $definition);
                    $this->execute($pdo, $statement, sprintf('index %s on %s', $index, $table));
                }
            }
        }
    }

    private function migrate(int $version): void
    {
        match ($version) {
            // Baseline: the missing column and index installation that follows brings a pre-versioning database to this version.
            1 => null,
            default => throw new SchemaVersionException(sprintf('No upgrade is defined for schema version %d.', $version)),
        };
    }

    private function runMigrations(PDO $pdo, int $from): void
    {
        for ($version = $from + 1; $version <= self::CURRENT_VERSION; $version++) {
            try {
                $this->migrate($version);
                $this->stamp($pdo, $version);
            } catch (Throwable $throwable) {
                throw new SchemaVersionException(
                    sprintf('Upgrade to schema version %d failed and the database remains at version %d: %s', $version, $version - 1, $throwable->getMessage()),
                    previous: $throwable,
                );
            }
        }
    }

    private function stamp(PDO $pdo, int $version): void
    {
        $pdo->beginTransaction();
        $pdo->exec('DELETE FROM schema_version');
        $pdo->exec(sprintf('INSERT INTO schema_version (version) VALUES (%d)', $version));
        $pdo->commit();
    }
}

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

use RuntimeException;

/**
 * The schema a backend's schema.sql defines, read back as tables with their column and index definitions so a
 * live database can be compared against it. Assumes one column or index per line, as the schema files are written.
 */
final class SchemaDefinition
{
    public function __construct(private readonly string $backend) {}

    /** @return string[] every statement in schema.sql, comments included */
    public function statements(): array
    {
        $statements = array_map(trim(...), explode(";\n", $this->contents()));

        return array_values(array_filter($statements, static fn(string $statement): bool => $statement !== ''));
    }

    /**
     * @return array<string, array{columns: array<string, string>, indexes: array<string, string>}> keyed by table; each
     *                                                                                               definition is the SQL fragment that creates it (an index fragment is a full CREATE INDEX statement on PostgreSQL)
     */
    public function tables(): array
    {
        $tables  = [];
        $indexes = [];

        foreach ($this->statements() as $statement) {
            $lines = array_values(array_filter(
                array_map(trim(...), explode("\n", $statement)),
                static fn(string $line): bool => $line !== '' && !str_starts_with($line, '--'),
            ));

            if (preg_match('/^CREATE TABLE IF NOT EXISTS (\w+) \($/', $lines[0] ?? '', $match) === 1) {
                $tables[$match[1]] = $this->parseTableBody(array_slice($lines, 1));
            } elseif (preg_match('/^CREATE (?:UNIQUE )?INDEX IF NOT EXISTS (\w+) ON (\w+) \(/', $lines[0] ?? '', $match) === 1) {
                $indexes[] = [$match[2], $match[1], implode(' ', $lines)];
            }
        }

        foreach ($indexes as [$table, $name, $definition]) {
            if (isset($tables[$table])) {
                $tables[$table]['indexes'][$name] = $definition;
            }
        }

        return $tables;
    }

    private function contents(): string
    {
        $dialect = $this->backend === 'pgsql' ? 'pgsql' : 'mysql';
        $path    = dirname(__DIR__, 4) . '/resources/sql/' . $dialect . '/schema.sql';
        $content = is_file($path) ? file_get_contents($path) : false;

        if ($content === false) {
            throw new RuntimeException(sprintf('Unable to read schema file: %s', $path));
        }

        return str_replace("\r\n", "\n", $content);
    }

    /**
     * @param string[] $lines
     * @return array{columns: array<string, string>, indexes: array<string, string>}
     */
    private function parseTableBody(array $lines): array
    {
        $columns = [];
        $indexes = [];

        foreach ($lines as $line) {
            if (str_starts_with($line, ')')) {
                break;
            }

            $line = rtrim($line, ',');

            if (preg_match('/^(?:(?:PRIMARY KEY|CONSTRAINT|FOREIGN KEY)\b|UNIQUE \()/i', $line) === 1) {
                continue;
            }

            if (preg_match('/^(?:UNIQUE )?KEY (\w+) \(/i', $line, $match) === 1) {
                $indexes[$match[1]] = $line;

                continue;
            }

            $columns[(string) strtok($line, ' ')] = $line;
        }

        return ['columns' => $columns, 'indexes' => $indexes];
    }
}

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

/**
 * PostgreSQL's ON CONFLICT upsert form, which needs the conflict target columns named explicitly.
 */
final class PostgreSqlDialect implements SqlDialect
{
    public function decodeBool(mixed $value): bool
    {
        return PdoBool::decode($value);
    }

    public function name(): string
    {
        return 'pgsql';
    }

    public function upsertSuffix(array $allColumns, array $conflictColumns, array $updateColumns): string
    {
        $assignments = array_map(static fn(string $column): string => sprintf('%s = EXCLUDED.%s', $column, $column), $updateColumns);

        return sprintf(' ON CONFLICT (%s) DO UPDATE SET %s', implode(', ', $conflictColumns), implode(', ', $assignments));
    }
}

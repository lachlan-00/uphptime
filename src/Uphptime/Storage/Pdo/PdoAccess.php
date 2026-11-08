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

/**
 * Shared query/execute helpers for the Pdo*Repository classes, each of which constructor-injects its
 * own `private readonly PDO $pdo` property that these methods read via $this. query()/execute()
 * translate a missing-table PDOException via PdoExceptionTranslator; rawQuery()/rawExecute() let a
 * transaction-wrapped caller handle rollback itself before translating.
 *
 * @property-read PDO $pdo the using class's own constructor-injected connection
 */
trait PdoAccess
{
    /** @param array<int, mixed> $params */
    private function execute(string $sql, array $params = []): void
    {
        try {
            $this->rawExecute($sql, $params);
        } catch (PDOException $exception) {
            PdoExceptionTranslator::translate($exception);
        }
    }

    /**
     * @param array<int, mixed> $params
     * @return array<int, array<string, mixed>>
     */
    private function query(string $sql, array $params = []): array
    {
        try {
            return $this->rawQuery($sql, $params);
        } catch (PDOException $exception) {
            PdoExceptionTranslator::translate($exception);
        }
    }

    /** @param array<int, mixed> $params */
    private function rawExecute(string $sql, array $params = []): void
    {
        $statement = $this->pdo->prepare($sql);
        $statement->execute($params);
    }

    /**
     * @param array<int, mixed> $params
     * @return array<int, array<string, mixed>>
     */
    private function rawQuery(string $sql, array $params = []): array
    {
        $statement = $this->pdo->prepare($sql);
        $statement->execute($params);

        /** @var array<int, array<string, mixed>> $rows */
        $rows = $statement->fetchAll();

        return $rows;
    }
}

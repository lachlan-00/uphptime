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
use Uphptime\History\DailyRollup;
use Uphptime\Storage\RollupRepositoryInterface;

/**
 * One row per site per day under the composite natural key (site_id, date), matching the one-file-
 * per-site-per-day model - point lookups and ordered range reads for free, no secondary index needed.
 */
final class PdoRollupRepository implements RollupRepositoryInterface
{
    use PdoAccess;

    private const COLUMNS = [
        'site_id', 'date', 'checks_total', 'checks_pass', 'checks_fail', 'checks_unknown',
        'checks_excluded_maintenance', 'checks_self_sourced', 'checks_peer_sourced', 'uptime_percent',
        'first_check_at', 'last_check_at', 'closed_at',
    ];

    public function __construct(
        private readonly PDO $pdo,
        private readonly SqlDialect $dialect,
    ) {}

    public function datesWithRollup(string $siteId): array
    {
        $rows = $this->query('SELECT date FROM rollups WHERE site_id = ? ORDER BY date ASC', [$siteId]);

        return array_map(static fn(array $row): string => (string) $row['date'], $rows);
    }

    public function delete(string $siteId, string $date): void
    {
        $this->execute('DELETE FROM rollups WHERE site_id = ? AND date = ?', [$siteId, $date]);
    }

    public function exists(string $siteId, string $date): bool
    {
        return $this->query('SELECT 1 AS present FROM rollups WHERE site_id = ? AND date = ?', [$siteId, $date]) !== [];
    }

    public function read(string $siteId, string $date): ?DailyRollup
    {
        $rows = $this->query('SELECT * FROM rollups WHERE site_id = ? AND date = ?', [$siteId, $date]);

        return $rows === [] ? null : $this->fromRow($rows[0]);
    }

    public function write(DailyRollup $rollup): void
    {
        $update = array_values(array_diff(self::COLUMNS, ['site_id', 'date']));
        $sql    = sprintf(
            'INSERT INTO rollups (%s) VALUES (%s)',
            implode(', ', self::COLUMNS),
            implode(', ', array_fill(0, count(self::COLUMNS), '?')),
        ) . $this->dialect->upsertSuffix(self::COLUMNS, ['site_id', 'date'], $update);

        $this->execute($sql, [
            $rollup->siteId,
            $rollup->date,
            $rollup->checksTotal,
            $rollup->checksPass,
            $rollup->checksFail,
            $rollup->checksUnknown,
            $rollup->checksExcludedMaintenance,
            $rollup->checksSelfSourced,
            $rollup->checksPeerSourced,
            $rollup->uptimePercent,
            $rollup->firstCheckAt !== null ? PdoTimestamp::format($rollup->firstCheckAt) : null,
            $rollup->lastCheckAt !== null ? PdoTimestamp::format($rollup->lastCheckAt) : null,
            PdoTimestamp::format($rollup->closedAt),
        ]);
    }

    /** @param array<string, mixed> $row */
    private function fromRow(array $row): DailyRollup
    {
        return new DailyRollup(
            siteId: (string) $row['site_id'],
            date: (string) $row['date'],
            checksTotal: (int) $row['checks_total'],
            checksPass: (int) $row['checks_pass'],
            checksFail: (int) $row['checks_fail'],
            checksUnknown: (int) $row['checks_unknown'],
            checksExcludedMaintenance: (int) $row['checks_excluded_maintenance'],
            checksSelfSourced: (int) $row['checks_self_sourced'],
            checksPeerSourced: (int) $row['checks_peer_sourced'],
            uptimePercent: $row['uptime_percent'] !== null ? (float) $row['uptime_percent'] : null,
            firstCheckAt: $row['first_check_at'] !== null ? PdoTimestamp::parse((string) $row['first_check_at']) : null,
            lastCheckAt: $row['last_check_at'] !== null ? PdoTimestamp::parse((string) $row['last_check_at']) : null,
            closedAt: PdoTimestamp::parse((string) $row['closed_at']),
        );
    }
}

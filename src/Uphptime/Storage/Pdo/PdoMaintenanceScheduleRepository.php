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
use Uphptime\Site\MaintenanceRepeatUnit;
use Uphptime\Site\MaintenanceSchedule;
use Uphptime\Storage\MaintenanceScheduleRepositoryInterface;

/**
 * The standalone maintenance_schedules catalog table - no site_id column, since a schedule is
 * defined once and assigned to sites via site_schedule_assignments (see PdoSiteRepository).
 */
final class PdoMaintenanceScheduleRepository implements MaintenanceScheduleRepositoryInterface
{
    use PdoAccess;

    private const COLUMNS = [
        'id', 'name', 'enabled', 'starts_at', 'duration_minutes', 'timezone',
        'repeat_every', 'repeat_unit', 'repeat_day_of_week', 'ends_on', 'note',
    ];

    public function __construct(
        private readonly PDO $pdo,
        private readonly SqlDialect $dialect,
    ) {}

    public function all(): array
    {
        return array_map(
            $this->fromRow(...),
            $this->query('SELECT * FROM maintenance_schedules ORDER BY id ASC'),
        );
    }

    public function find(string $id): ?MaintenanceSchedule
    {
        $rows = $this->query('SELECT * FROM maintenance_schedules WHERE id = ?', [$id]);

        return $rows === [] ? null : $this->fromRow($rows[0]);
    }

    public function remove(string $id): void
    {
        $this->execute('DELETE FROM maintenance_schedules WHERE id = ?', [$id]);
    }

    public function save(MaintenanceSchedule $schedule): void
    {
        $update = array_values(array_diff(self::COLUMNS, ['id']));
        $sql    = sprintf(
            'INSERT INTO maintenance_schedules (%s) VALUES (%s)',
            implode(', ', self::COLUMNS),
            implode(', ', array_fill(0, count(self::COLUMNS), '?')),
        ) . $this->dialect->upsertSuffix(self::COLUMNS, ['id'], $update);

        $this->execute($sql, [
            $schedule->id,
            $schedule->name,
            $schedule->enabled ? 1 : 0,
            PdoTimestamp::format($schedule->startsAt),
            $schedule->durationMinutes,
            $schedule->timezone,
            $schedule->repeatEvery,
            $schedule->repeatUnit->value,
            $schedule->repeatDayOfWeek,
            $schedule->endsOn instanceof \DateTimeImmutable ? PdoTimestamp::format($schedule->endsOn) : null,
            $schedule->note,
        ]);
    }

    /** @param array<string, mixed> $row */
    private function fromRow(array $row): MaintenanceSchedule
    {
        return new MaintenanceSchedule(
            id: (string) $row['id'],
            name: (string) $row['name'],
            enabled: $this->dialect->decodeBool($row['enabled']),
            startsAt: PdoTimestamp::parse((string) $row['starts_at']),
            durationMinutes: (int) $row['duration_minutes'],
            timezone: (string) $row['timezone'],
            repeatEvery: (int) $row['repeat_every'],
            repeatUnit: MaintenanceRepeatUnit::from((string) $row['repeat_unit']),
            repeatDayOfWeek: $row['repeat_day_of_week'] !== null ? (int) $row['repeat_day_of_week'] : null,
            endsOn: $row['ends_on'] !== null ? PdoTimestamp::parse((string) $row['ends_on']) : null,
            note: $row['note'],
        );
    }
}

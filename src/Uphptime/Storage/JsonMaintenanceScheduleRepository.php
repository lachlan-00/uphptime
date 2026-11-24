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

namespace Uphptime\Storage;

use DateTimeImmutable;
use DateTimeInterface;
use Uphptime\Site\MaintenanceRepeatUnit;
use Uphptime\Site\MaintenanceSchedule;
use Uphptime\Support\AtomicWriter;

/**
 * maintenance_schedules.json as a single atomically-rewritten file, the same pattern as JsonPeerRepository.
 */
final class JsonMaintenanceScheduleRepository implements MaintenanceScheduleRepositoryInterface
{
    public function __construct(
        private readonly AtomicWriter $writer,
        private readonly string $path,
    ) {}

    public function all(): array
    {
        return array_map(
            $this->scheduleFromArray(...),
            $this->schedulesFrom($this->decodeFile($this->writer->read($this->path))),
        );
    }

    public function find(string $id): ?MaintenanceSchedule
    {
        foreach ($this->all() as $schedule) {
            if ($schedule->id === $id) {
                return $schedule;
            }
        }

        return null;
    }

    // readModifyWrite() holds one lock across the whole sequence, so concurrent writes can't race on a stale read.
    public function remove(string $id): void
    {
        $this->writer->readModifyWrite($this->path, function (?string $current) use ($id): string {
            $schedules = array_values(array_filter(
                $this->schedulesFrom($this->decodeFile($current)),
                static fn(array $existing): bool => $existing['id'] !== $id,
            ));

            return $this->encodeFile(['schema_version' => 1, 'maintenance_schedules' => $schedules]);
        });
    }

    public function save(MaintenanceSchedule $schedule): void
    {
        $this->writer->readModifyWrite($this->path, function (?string $current) use ($schedule): string {
            $schedules = $this->schedulesFrom($this->decodeFile($current));
            $found     = false;

            foreach ($schedules as $index => $existing) {
                if ($existing['id'] === $schedule->id) {
                    $schedules[$index]  = $this->scheduleToArray($schedule);
                    $found              = true;
                    break;
                }
            }

            if (!$found) {
                $schedules[] = $this->scheduleToArray($schedule);
            }

            return $this->encodeFile(['schema_version' => 1, 'maintenance_schedules' => $schedules]);
        });
    }

    /** @return array<string, mixed> */
    private function decodeFile(?string $contents): array
    {
        if ($contents === null) {
            return ['schema_version' => 1, 'maintenance_schedules' => []];
        }

        $decoded = json_decode($contents, true);

        return is_array($decoded) ? $decoded : ['schema_version' => 1, 'maintenance_schedules' => []];
    }

    /** @param array{schema_version: int, maintenance_schedules: array<int, array<string, mixed>>} $data */
    private function encodeFile(array $data): string
    {
        return json_encode($data, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR) . "\n";
    }

    /** @param array<string, mixed> $data */
    private function scheduleFromArray(array $data): MaintenanceSchedule
    {
        return new MaintenanceSchedule(
            id: (string) $data['id'],
            name: (string) $data['name'],
            enabled: (bool) $data['enabled'],
            startsAt: new DateTimeImmutable((string) $data['starts_at']),
            durationMinutes: (int) $data['duration_minutes'],
            timezone: (string) $data['timezone'],
            repeatEvery: (int) $data['repeat_every'],
            repeatUnit: MaintenanceRepeatUnit::from((string) $data['repeat_unit']),
            repeatDayOfWeek: $data['repeat_day_of_week'] !== null ? (int) $data['repeat_day_of_week'] : null,
            endsOn: $data['ends_on'] !== null ? new DateTimeImmutable((string) $data['ends_on']) : null,
            note: $data['note'] ?? null,
        );
    }

    /**
     * @param array<string, mixed> $data
     * @return array<int, array<string, mixed>>
     */
    private function schedulesFrom(array $data): array
    {
        $rawSchedules = $data['maintenance_schedules'] ?? [];
        if (!is_array($rawSchedules)) {
            return [];
        }

        $schedules = [];
        foreach ($rawSchedules as $rawSchedule) {
            if (is_array($rawSchedule)) {
                $schedules[] = $rawSchedule;
            }
        }

        return $schedules;
    }

    /** @return array<string, mixed> */
    private function scheduleToArray(MaintenanceSchedule $schedule): array
    {
        return [
            'id' => $schedule->id,
            'name' => $schedule->name,
            'enabled' => $schedule->enabled,
            'starts_at' => $schedule->startsAt->format(DateTimeInterface::ATOM),
            'duration_minutes' => $schedule->durationMinutes,
            'timezone' => $schedule->timezone,
            'repeat_every' => $schedule->repeatEvery,
            'repeat_unit' => $schedule->repeatUnit->value,
            'repeat_day_of_week' => $schedule->repeatDayOfWeek,
            'ends_on' => $schedule->endsOn?->format(DateTimeInterface::ATOM),
            'note' => $schedule->note,
        ];
    }
}

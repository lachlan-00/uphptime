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

namespace Uphptime\Cli\Commands;

use DateTimeImmutable;
use DateTimeInterface;
use DateTimeZone;
use Exception;
use Uphptime\Cli\ArgvOptions;
use Uphptime\Cli\CommandInterface;
use Uphptime\Cli\ConsoleOutputInterface;
use Uphptime\Site\MaintenanceRepeatUnit;
use Uphptime\Site\MaintenanceSchedule;
use Uphptime\Storage\MaintenanceScheduleRepositoryInterface;

/**
 * Adds a recurring maintenance schedule to the standalone catalog, to be assigned to any number of
 * sites via `maintenance:schedule:assign` rather than duplicated per site.
 */
final class MaintenanceScheduleAddCommand implements CommandInterface
{
    private const DAY_NAMES = [
        1 => 'monday', 2 => 'tuesday', 3 => 'wednesday', 4 => 'thursday',
        5 => 'friday', 6 => 'saturday', 7 => 'sunday',
    ];

    public function __construct(
        private readonly MaintenanceScheduleRepositoryInterface $maintenanceScheduleRepository,
        private readonly ConsoleOutputInterface $output,
    ) {}

    /** @return array<string, mixed> */
    public static function scheduleToArray(MaintenanceSchedule $schedule): array
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

    public function run(array $args): int
    {
        $options  = ArgvOptions::parse($args);
        $name     = $options['name'] ?? null;
        $start    = $options['start'] ?? null;
        $timezone = $options['timezone'] ?? null;

        if ($name === null || $start === null || $timezone === null || !isset($options['duration-minutes'])) {
            $this->output->writeErrorLine(json_encode(
                ['error' => 'Missing required --name, --start, --duration-minutes and --timezone options.'],
                JSON_THROW_ON_ERROR,
            ));

            return 1;
        }

        try {
            $zone     = new DateTimeZone($timezone);
            $startsAt = new DateTimeImmutable($start, $zone);
        } catch (Exception) {
            $this->output->writeErrorLine(json_encode(
                ['error' => 'Unable to parse --start as a date/time, or --timezone is unknown.'],
                JSON_THROW_ON_ERROR,
            ));

            return 1;
        }

        $repeatUnit = MaintenanceRepeatUnit::tryFrom(strtolower($options['repeat-unit'] ?? 'week'));
        if ($repeatUnit === null) {
            $this->output->writeErrorLine(json_encode(
                ['error' => '--repeat-unit must be one of: hour, day, week, month.'],
                JSON_THROW_ON_ERROR,
            ));

            return 1;
        }

        $repeatEvery = (int) ($options['repeat-every'] ?? '1');
        if ($repeatEvery <= 0) {
            $this->output->writeErrorLine(json_encode(['error' => '--repeat-every must be a positive integer.'], JSON_THROW_ON_ERROR));

            return 1;
        }

        $repeatDayOfWeek = null;
        if (isset($options['repeat-day-of-week'])) {
            $repeatDayOfWeek = $this->resolveDayOfWeek($options['repeat-day-of-week']);
            if ($repeatDayOfWeek === null) {
                $this->output->writeErrorLine(json_encode(
                    ['error' => '--repeat-day-of-week must be 1-7 (Monday-Sunday) or a day name.'],
                    JSON_THROW_ON_ERROR,
                ));

                return 1;
            }
        }

        if ($repeatDayOfWeek !== null && $repeatUnit !== MaintenanceRepeatUnit::Week) {
            $this->output->writeErrorLine(json_encode(
                ['error' => '--repeat-day-of-week only applies when --repeat-unit=week.'],
                JSON_THROW_ON_ERROR,
            ));

            return 1;
        }

        $durationMinutes = (int) $options['duration-minutes'];
        if ($durationMinutes <= 0) {
            $this->output->writeErrorLine(json_encode(['error' => '--duration-minutes must be a positive integer.'], JSON_THROW_ON_ERROR));

            return 1;
        }

        $endsOn = null;
        if (isset($options['ends-on'])) {
            try {
                $endsOn = new DateTimeImmutable($options['ends-on'], $zone);
            } catch (Exception) {
                $this->output->writeErrorLine(json_encode(['error' => 'Unable to parse --ends-on as a date.'], JSON_THROW_ON_ERROR));

                return 1;
            }
        }

        $schedule = new MaintenanceSchedule(
            id: bin2hex(random_bytes(4)),
            name: $name,
            enabled: ($options['enabled'] ?? '1') !== '0',
            startsAt: $startsAt,
            durationMinutes: $durationMinutes,
            timezone: $timezone,
            repeatEvery: $repeatEvery,
            repeatUnit: $repeatUnit,
            repeatDayOfWeek: $repeatDayOfWeek,
            endsOn: $endsOn,
            note: $options['note'] ?? null,
        );

        $this->maintenanceScheduleRepository->save($schedule);

        $this->output->writeLine(json_encode([
            'schedule' => self::scheduleToArray($schedule),
        ], JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT));

        return 0;
    }

    private function resolveDayOfWeek(string $dayInput): ?int
    {
        if (ctype_digit($dayInput)) {
            $day = (int) $dayInput;

            return $day >= 1 && $day <= 7 ? $day : null;
        }

        $day = array_search(strtolower($dayInput), self::DAY_NAMES, true);

        return $day === false ? null : $day;
    }
}

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

namespace Uphptime\History;

use DateTimeImmutable;
use DateTimeInterface;

/**
 * One immutable day's totals, written once when the day closes and never rewritten.
 */
final class DailyRollup
{
    public function __construct(
        public readonly string $siteId,
        public readonly string $date,
        public readonly int $checksTotal,
        public readonly int $checksPass,
        public readonly int $checksFail,
        public readonly int $checksUnknown,
        public readonly int $checksExcludedMaintenance,
        public readonly int $checksSelfSourced,
        public readonly int $checksPeerSourced,
        public readonly ?float $uptimePercent,
        public readonly ?DateTimeImmutable $firstCheckAt,
        public readonly ?DateTimeImmutable $lastCheckAt,
        public readonly DateTimeImmutable $closedAt,
    ) {}

    /** @param array<string, mixed> $data */
    public static function fromArray(array $data): self
    {
        return new self(
            siteId: (string) $data['site_id'],
            date: (string) $data['date'],
            checksTotal: (int) $data['checks_total'],
            checksPass: (int) $data['checks_pass'],
            checksFail: (int) $data['checks_fail'],
            checksUnknown: (int) $data['checks_unknown'],
            checksExcludedMaintenance: (int) $data['checks_excluded_maintenance'],
            checksSelfSourced: (int) $data['checks_self_sourced'],
            checksPeerSourced: (int) $data['checks_peer_sourced'],
            uptimePercent: $data['uptime_percent'] !== null ? (float) $data['uptime_percent'] : null,
            firstCheckAt: $data['first_check_at'] !== null ? new DateTimeImmutable((string) $data['first_check_at']) : null,
            lastCheckAt: $data['last_check_at'] !== null ? new DateTimeImmutable((string) $data['last_check_at']) : null,
            closedAt: new DateTimeImmutable((string) $data['closed_at']),
        );
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'schema_version' => 1,
            'site_id' => $this->siteId,
            'date' => $this->date,
            'checks_total' => $this->checksTotal,
            'checks_pass' => $this->checksPass,
            'checks_fail' => $this->checksFail,
            'checks_unknown' => $this->checksUnknown,
            'checks_excluded_maintenance' => $this->checksExcludedMaintenance,
            'checks_self_sourced' => $this->checksSelfSourced,
            'checks_peer_sourced' => $this->checksPeerSourced,
            'uptime_percent' => $this->uptimePercent,
            'first_check_at' => $this->firstCheckAt?->format(DateTimeInterface::ATOM),
            'last_check_at' => $this->lastCheckAt?->format(DateTimeInterface::ATOM),
            'closed_at' => $this->closedAt->format(DateTimeInterface::ATOM),
        ];
    }
}

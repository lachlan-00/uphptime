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

namespace Uphptime\Reporting;

/**
 * An aggregated uptime figure; states how much of the period is unknown/self-sourced, never just a number.
 */
final class UptimeReport
{
    public function __construct(
        public readonly int $checksTotal,
        public readonly int $checksPass,
        public readonly int $checksUnknown,
        public readonly int $checksExcludedMaintenance,
        public readonly int $checksSelfSourced,
    ) {}

    public static function empty(): self
    {
        return new self(0, 0, 0, 0, 0);
    }

    public function merge(self $other): self
    {
        return new self(
            $this->checksTotal + $other->checksTotal,
            $this->checksPass + $other->checksPass,
            $this->checksUnknown + $other->checksUnknown,
            $this->checksExcludedMaintenance + $other->checksExcludedMaintenance,
            $this->checksSelfSourced + $other->checksSelfSourced,
        );
    }

    public function selfSourcedPercent(): ?float
    {
        return $this->checksTotal > 0 ? round(($this->checksSelfSourced / $this->checksTotal) * 100, 4) : null;
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'checks_total' => $this->checksTotal,
            'checks_pass' => $this->checksPass,
            'checks_unknown' => $this->checksUnknown,
            'checks_excluded_maintenance' => $this->checksExcludedMaintenance,
            'uptime_percent' => $this->uptimePercent(),
            'unknown_percent' => $this->unknownPercent(),
            'self_sourced_percent' => $this->selfSourcedPercent(),
        ];
    }

    public function unknownPercent(): ?float
    {
        return $this->checksTotal > 0 ? round(($this->checksUnknown / $this->checksTotal) * 100, 4) : null;
    }

    // Percentage of countable checks (excludes unknown/maintenance) that passed; null when nothing counts.
    public function uptimePercent(): ?float
    {
        $denominator = $this->checksTotal - $this->checksUnknown - $this->checksExcludedMaintenance;

        return $denominator > 0 ? round(($this->checksPass / $denominator) * 100, 4) : null;
    }
}

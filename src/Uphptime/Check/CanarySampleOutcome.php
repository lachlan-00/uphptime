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

namespace Uphptime\Check;

use Uphptime\History\HistoryRecord;

/**
 * What a canary sample produced: zero or more extra history records (never state-mutating) plus
 * whether the primary record they relate to should be retroactively flagged before it is written.
 */
final class CanarySampleOutcome
{
    /** @param HistoryRecord[] $extraRecords */
    private function __construct(
        public readonly bool $flagged,
        public readonly array $extraRecords,
    ) {}

    public static function agreed(HistoryRecord $canaryRecord): self
    {
        return new self(false, [$canaryRecord]);
    }

    public static function attemptFailed(HistoryRecord $canaryRecord): self
    {
        return new self(false, [$canaryRecord]);
    }

    public static function disagreedConfirmedFalsePass(HistoryRecord $canaryRecord, HistoryRecord $tieBreakRecord): self
    {
        return new self(true, [$canaryRecord, $tieBreakRecord]);
    }

    public static function disagreedUnresolved(HistoryRecord $canaryRecord): self
    {
        return new self(true, [$canaryRecord]);
    }

    public static function disagreedVindicatedOriginal(HistoryRecord $canaryRecord, HistoryRecord $tieBreakRecord): self
    {
        return new self(true, [$canaryRecord, $tieBreakRecord]);
    }

    public static function notSampled(): self
    {
        return new self(false, []);
    }
}

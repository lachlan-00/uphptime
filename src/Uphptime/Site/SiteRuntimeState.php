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

namespace Uphptime\Site;

use DateTimeImmutable;
use Uphptime\Check\CheckOutcome;

/**
 * A site's mutable monitoring state, embedded in its sites.json record rather than a separate store -
 * two files can disagree after a crash between writes, and one atomic rewrite closes that off.
 */
final class SiteRuntimeState
{
    public function __construct(
        public readonly SiteState $status,
        public readonly int $consecutiveFails,
        public readonly ?CheckOutcome $lastOutcome,
        public readonly ?DateTimeImmutable $lastCheckedAt,
        public readonly DateTimeImmutable $nextDueAt,
        public readonly ?DateTimeImmutable $lastTransitionAt,
    ) {}

    // Unknown on an inconclusive or stale check; keep the stored status for the next valid result.
    public function effectiveStatus(DateTimeImmutable $now, int $staleAfterSeconds): SiteState
    {
        if ($this->lastCheckedAt === null || $this->lastOutcome === CheckOutcome::Error) {
            return SiteState::Unknown;
        }

        $age = $now->getTimestamp() - $this->lastCheckedAt->getTimestamp();

        return $age > $staleAfterSeconds ? SiteState::Unknown : $this->status;
    }

    // Records a check that produced no valid result; status and the fail counter are left untouched.
    public function withErrorChecked(DateTimeImmutable $checkedAt): self
    {
        return new self(
            $this->status,
            $this->consecutiveFails,
            CheckOutcome::Error,
            $checkedAt,
            $this->nextDueAt,
            $this->lastTransitionAt,
        );
    }

    // Returns a copy with a new next-due time; used by CheckRunner after every check.
    public function withNextDueAt(DateTimeImmutable $nextDueAt): self
    {
        return new self(
            $this->status,
            $this->consecutiveFails,
            $this->lastOutcome,
            $this->lastCheckedAt,
            $nextDueAt,
            $this->lastTransitionAt,
        );
    }

    // Records a verified pass/fail outcome, moving to the given status and fail count.
    public function withTransition(SiteState $status, int $consecutiveFails, CheckOutcome $outcome, DateTimeImmutable $checkedAt): self
    {
        $transitioned = $status !== $this->status;

        return new self(
            $status,
            $consecutiveFails,
            $outcome,
            $checkedAt,
            $this->nextDueAt,
            $transitioned ? $checkedAt : $this->lastTransitionAt,
        );
    }
}

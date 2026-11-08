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
 * The transition table is peer-agnostic ("Fail -> suspect", "Second fail -> down"); the runner
 * determines whether an observation is sufficiently reliable before applying it.
 */
final class SiteStateMachine
{
    public function apply(SiteRuntimeState $state, CheckOutcome $outcome, DateTimeImmutable $checkedAt): SiteRuntimeState
    {
        if ($outcome === CheckOutcome::Error) {
            return $state->withErrorChecked($checkedAt);
        }

        if ($outcome === CheckOutcome::Pass) {
            return $state->withTransition(SiteState::Up, 0, $outcome, $checkedAt);
        }

        return match ($state->status) {
            SiteState::Up, SiteState::Unknown => $state->withTransition(SiteState::Suspect, 1, $outcome, $checkedAt),
            SiteState::Suspect, SiteState::Down => $state->withTransition(
                SiteState::Down,
                $state->consecutiveFails + 1,
                $outcome,
                $checkedAt,
            ),
        };
    }
}

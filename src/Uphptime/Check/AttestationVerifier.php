<?php

declare(strict_types=1);

/**
 * vim:set softtabstop=4 shiftwidth=4 expandtab:
 *
 * LICENSE: GNU Affero General Public License, version 3 (AGPL-3.0-or-later)
 * Copyright uPHPtime contributors, 2026
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

use Uphptime\Api\ChallengeToken\ChallengeTokenStoreInterface;
use Uphptime\Site\Site;
use Uphptime\Support\ClockInterface;

/**
 * The owner-side half of result attestation: decides whether a delegated result is usable, and what
 * attestation level it earned, since only the owner holds the real token to compare against.
 */
final class AttestationVerifier
{
    public function __construct(
        private readonly ChallengeTokenStoreInterface $tokenStore,
        private readonly ClockInterface $clock,
    ) {}

    /** @param array<string, mixed>|null $evidence */
    public function verify(Site $site, CheckResult $result, ?array $evidence): AttestationVerdict
    {
        if ($evidence === null) {
            return AttestationVerdict::valid('none');
        }

        // Only a Pass can be falsely attested to - nothing to fake-verify about a down or erroring result.
        if (!$site->usesChallengeToken() || $result->outcome !== CheckOutcome::Pass) {
            return AttestationVerdict::valid('evidence');
        }

        $expected = $this->tokenStore->current($site->id, $this->clock->now());
        $observed = $evidence['challenge_token_observed'] ?? null;

        if ($expected instanceof \Uphptime\Api\ChallengeToken\ChallengeToken && is_string($observed) && hash_equals($expected->token, $observed)) {
            return AttestationVerdict::valid('token');
        }

        return AttestationVerdict::invalid();
    }
}

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
 * The owner-side half of result attestation: decides whether a delegated result is even usable, and
 * what attestation level it earned. Every one of the three acquisition loops (PeerResultAcquirer,
 * DownConfirmer, CanarySampler) calls this on a non-exception delegated result instead of trusting
 * whatever `attestation` value the pinger itself reported - only the owner holds the real token to
 * compare against, so only the owner can honestly say whether Layer 1 passed.
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

        // Nothing to fake-verify about a site reporting itself down or erroring - only a Pass can be
        // falsely attested to.
        if (!$site->usesChallengeToken() || $result->outcome !== CheckOutcome::Pass) {
            return AttestationVerdict::valid('evidence');
        }

        $expected = $this->tokenStore->current($site->id, $this->clock->now());
        $observed = $evidence['challenge_token_observed'] ?? null;

        if ($expected !== null && is_string($observed) && hash_equals($expected->token, $observed)) {
            return AttestationVerdict::valid('token');
        }

        return AttestationVerdict::invalid();
    }
}

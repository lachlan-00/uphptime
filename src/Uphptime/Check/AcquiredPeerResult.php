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

use Uphptime\Peer\Peer;

/**
 * One valid, signed result obtained from a peer during an acquisition attempt, or null $peer/$keyId
 * when it was a self-fallback (no different peer existed to ask).
 */
final class AcquiredPeerResult
{
    /** @param array<string, mixed>|null $evidence */
    public function __construct(
        public readonly ?Peer $peer,
        public readonly ?string $keyId,
        public readonly CheckResult $result,
        public readonly ?array $evidence,
        public readonly string $attestation,
    ) {}

    // The history/state "source" string for this result: "self" or "peer:<key_id>".
    public function source(): string
    {
        return $this->keyId === null ? 'self' : 'peer:' . $this->keyId;
    }
}

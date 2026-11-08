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

namespace Uphptime\Api\Client;

use Uphptime\Check\CheckResult;

/**
 * A verified, signed result from a delegated peer call, plus the key ID that signed it (the wire
 * identity, never the peer's local label). servedFromCache/cacheAgeSeconds default to the
 * always-fresh milestone-2 values; a peer's pinger cache is the only producer of a cached one.
 */
final class DelegatedCheckResult
{
    /** @param array<string, mixed>|null $evidence */
    public function __construct(
        public readonly CheckResult $result,
        public readonly string $verifiedKeyId,
        public readonly ?array $evidence,
        public readonly string $attestation,
        public readonly bool $servedFromCache = false,
        public readonly int $cacheAgeSeconds = 0,
    ) {}
}

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

/**
 * What AttestationVerifier decided about one delegated result: whether to accept it at all, and (if
 * accepted) the attestation level this specific result earned - recomputed owner-side every time,
 * never trusted from whatever the pinger itself claimed on the wire.
 */
final class AttestationVerdict
{
    private function __construct(
        public readonly bool $isValid,
        public readonly string $attestation,
    ) {}

    // A missing/wrong challenge token on an otherwise-valid Pass - never a down signal, just unusable.
    public static function invalid(): self
    {
        return new self(false, 'none');
    }

    public static function valid(string $attestation): self
    {
        return new self(true, $attestation);
    }
}

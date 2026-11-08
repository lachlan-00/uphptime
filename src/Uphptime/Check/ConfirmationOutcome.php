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
 * The result of trying to confirm a primary Fail: either a settled Pass/Fail from a genuinely
 * different peer (or a self-check fallback), or unconfirmed when no reliable second opinion was obtainable.
 */
final class ConfirmationOutcome
{
    /** @param array<string, mixed>|null $evidence */
    private function __construct(
        public readonly bool $confirmed,
        public readonly ?CheckResult $result,
        public readonly ?string $source,
        public readonly ?array $evidence,
        public readonly ?string $attestation,
        public readonly ?Peer $peer,
    ) {}

    /** @param array<string, mixed>|null $evidence */
    public static function resolved(CheckResult $result, string $source, ?array $evidence, string $attestation, ?Peer $peer = null): self
    {
        return new self(true, $result, $source, $evidence, $attestation, $peer);
    }

    public static function unconfirmed(): self
    {
        return new self(false, null, null, null, null, null);
    }
}

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

namespace Uphptime\Api;

use Uphptime\Peer\Peer;
use Uphptime\Peer\PeerKey;

/**
 * The outcome of HmacAuthMiddleware's verification. $rateLimited distinguishes the one non-secret
 * failure (429) from every auth failure, which the middleware answers with an identical 401 body
 * regardless of which check failed.
 */
final class AuthResult
{
    private function __construct(
        public readonly bool $authenticated,
        public readonly ?Peer $peer,
        public readonly ?PeerKey $key,
        public readonly bool $rateLimited,
    ) {}

    public static function rateLimited(): self
    {
        return new self(false, null, null, true);
    }

    public static function success(Peer $peer, PeerKey $key): self
    {
        return new self(true, $peer, $key, false);
    }

    public static function unauthorized(): self
    {
        return new self(false, null, null, false);
    }
}

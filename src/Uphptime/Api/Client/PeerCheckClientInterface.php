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

use Uphptime\Peer\Peer;
use Uphptime\Site\Site;

/**
 * Asks one peer to perform one check and returns its verified, signed result. Throws
 * DelegatedCheckFailedException for any non-response/invalid/unverifiable outcome; a verified
 * CheckOutcome::Error is still a valid DelegatedCheckResult, since the peer genuinely signed it.
 */
interface PeerCheckClientInterface
{
    public function requestCheck(Peer $peer, Site $site, float $timeoutSeconds): DelegatedCheckResult;
}

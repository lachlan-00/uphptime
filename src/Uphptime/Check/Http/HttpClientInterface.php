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

namespace Uphptime\Check\Http;

use Uphptime\Site\ChallengeDelivery;

/**
 * Performs one GET request. Throws RuntimeException for any transport failure (DNS, timeout, refused).
 * $challengeDelivery is result attestation Layer 1: a self-check client (CurlHttpClient) ignores it -
 * only a pinger-delegated client (SafeHttpClient) ever has a token worth observing and reporting.
 */
interface HttpClientInterface
{
    public function get(string $url, float $timeoutSeconds, string $userAgent, ?ChallengeDelivery $challengeDelivery = null): HttpResponse;
}

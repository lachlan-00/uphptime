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

use RuntimeException;
use Uphptime\Site\ChallengeDelivery;
use Uphptime\Support\NetworkStackInterface;
use Uphptime\Version;

/**
 * Thin curl wrapper; no redirects are followed since a check probes the exact given URL. A self-check
 * never has result attestation to report, so $challengeDelivery is accepted (interface parity) but unused.
 */
final class CurlHttpClient implements HttpClientInterface
{
    public function __construct(private readonly NetworkStackInterface $networkStack) {}

    public function get(string $url, float $timeoutSeconds, string $userAgent, ?ChallengeDelivery $challengeDelivery = null): HttpResponse
    {
        $handle = curl_init($url);
        if ($handle === false) {
            throw new RuntimeException(sprintf('Unable to initialize cURL for: %s', $url));
        }

        $timeoutMs = (int) ($timeoutSeconds * 1000);
        curl_setopt($handle, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($handle, CURLOPT_FOLLOWLOCATION, false);
        curl_setopt($handle, CURLOPT_TIMEOUT_MS, $timeoutMs);
        curl_setopt($handle, CURLOPT_CONNECTTIMEOUT_MS, $timeoutMs);
        curl_setopt($handle, CURLOPT_USERAGENT, $userAgent !== '' ? $userAgent : Version::USER_AGENT);
        // Without this, a dual-stack target with no real IPv6 route from here wastes the whole
        // connect timeout failing over IPv6 first before curl falls back to IPv4 itself.
        if (!$this->networkStack->hasIpv6Route()) {
            curl_setopt($handle, CURLOPT_IPRESOLVE, CURL_IPRESOLVE_V4);
        }

        $start     = microtime(true);
        $body      = curl_exec($handle);
        $elapsedMs = (int) round((microtime(true) - $start) * 1000);

        if ($body === false || !is_string($body)) {
            throw new RuntimeException(curl_error($handle) !== '' ? curl_error($handle) : 'Unknown cURL error.');
        }

        $statusCode = (int) curl_getinfo($handle, CURLINFO_RESPONSE_CODE);

        return new HttpResponse($statusCode, $body, $elapsedMs);
    }
}

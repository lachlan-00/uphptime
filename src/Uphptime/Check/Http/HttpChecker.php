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
use Uphptime\Check\CheckConfigurationException;
use Uphptime\Check\CheckerInterface;
use Uphptime\Check\CheckResult;
use Uphptime\Site\Site;

/**
 * Passes on any 2xx or 3xx status code. Redirects aren't followed (CurlHttpClient), so a 3xx here means
 * the origin itself answered with a valid redirect - e.g. to an SSO login page - which means it's up,
 * regardless of where it points. Only a genuine non-response (4xx/5xx/connection failure) counts as down.
 */
final class HttpChecker implements CheckerInterface
{
    public function __construct(
        private readonly HttpClientInterface $client,
        private readonly string $userAgent,
    ) {}

    public function check(Site $site, float $timeoutSeconds): CheckResult
    {
        if ($site->url === null) {
            throw new CheckConfigurationException(sprintf('Site "%s" has no URL configured for an HTTP check.', $site->id));
        }

        try {
            $response = $this->client->get($site->url, $timeoutSeconds, $this->userAgent, $site->challengeDelivery);
        } catch (RuntimeException $exception) {
            return CheckResult::fail($exception->getMessage());
        }

        if ($response->statusCode < 200 || $response->statusCode >= 400) {
            return CheckResult::fail('bad_status', $response->statusCode, $response->elapsedMs);
        }

        return CheckResult::pass($response->statusCode, $response->elapsedMs);
    }
}

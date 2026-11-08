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
use Uphptime\Site\KeywordMode;
use Uphptime\Site\Site;

/**
 * A 2xx status is necessary but not sufficient: the keyword match (per the site's mode) must also pass.
 */
final class HttpKeywordChecker implements CheckerInterface
{
    public function __construct(
        private readonly HttpClientInterface $client,
        private readonly string $userAgent,
    ) {}

    public function check(Site $site, float $timeoutSeconds): CheckResult
    {
        if ($site->url === null || $site->keyword === null || $site->keywordMode === null) {
            throw new CheckConfigurationException(
                sprintf('Site "%s" is missing url/keyword/keyword_mode for an HTTP+keyword check.', $site->id),
            );
        }

        try {
            $response = $this->client->get($site->url, $timeoutSeconds, $this->userAgent, $site->challengeDelivery);
        } catch (RuntimeException $exception) {
            return CheckResult::fail($exception->getMessage());
        }

        if ($response->statusCode < 200 || $response->statusCode >= 300) {
            return CheckResult::fail('non_2xx_status', $response->statusCode, $response->elapsedMs);
        }

        $contains = str_contains($response->body, $site->keyword);
        $matched  = $site->keywordMode === KeywordMode::Contains ? $contains : !$contains;

        if (!$matched) {
            return CheckResult::fail('keyword_mismatch', $response->statusCode, $response->elapsedMs, $matched);
        }

        return CheckResult::pass($response->statusCode, $response->elapsedMs, $matched);
    }
}

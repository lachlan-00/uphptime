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

namespace Uphptime\Pinger;

use Uphptime\Site\Site;

/**
 * Identifies a check by what it probes, not by any site id - two different owners asking about the
 * same target and check type hash to the same cache entry, which is the whole point of the cache.
 */
final class CacheFingerprint
{
    public static function forSite(Site $site): string
    {
        return hash('sha256', (string) json_encode([
            'check_type' => $site->checkType->value,
            'url' => $site->url,
            'host' => $site->host,
            'port' => $site->port,
            'keyword' => $site->keyword,
            'keyword_mode' => $site->keywordMode?->value,
        ], JSON_THROW_ON_ERROR));
    }
}

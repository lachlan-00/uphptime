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

namespace Uphptime\Api\ChallengeToken;

use DateTimeImmutable;

/**
 * Short-lived, non-durable per-site challenge tokens (never history), exactly like NonceStoreInterface/
 * RateLimiterInterface's operational-data model.
 */
interface ChallengeTokenStoreInterface
{
    // Returns the currently-live token for $siteId, or null if none is live right now.
    public function current(string $siteId, DateTimeImmutable $now): ?ChallengeToken;

    // Mints and stores a fresh token for $siteId, replacing any previous one, valid for $ttlSeconds.
    public function issue(string $siteId, DateTimeImmutable $now, int $ttlSeconds): ChallengeToken;
}

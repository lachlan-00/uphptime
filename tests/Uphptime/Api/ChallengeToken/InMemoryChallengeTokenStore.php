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

namespace Uphptime\Tests\Api\ChallengeToken;

use DateTimeImmutable;
use Uphptime\Api\ChallengeToken\ChallengeToken;
use Uphptime\Api\ChallengeToken\ChallengeTokenStoreInterface;

/**
 * An in-memory ChallengeTokenStoreInterface double for tests that don't need real JSON/flock behavior.
 */
final class InMemoryChallengeTokenStore implements ChallengeTokenStoreInterface
{
    /** @var array<string, ChallengeToken> */
    private array $tokens = [];

    // A fixed token lets a test predict what issue() will mint, so it can script a peer response
    // claiming to have observed that exact value ahead of time - real issuance is always random.
    public function __construct(private readonly ?string $fixedToken = null) {}

    public function current(string $siteId, DateTimeImmutable $now): ?ChallengeToken
    {
        $token = $this->tokens[$siteId] ?? null;

        return $token !== null && $token->isValidAt($now) ? $token : null;
    }

    public function issue(string $siteId, DateTimeImmutable $now, int $ttlSeconds): ChallengeToken
    {
        $token = new ChallengeToken($siteId, $this->fixedToken ?? bin2hex(random_bytes(16)), $now, $now->modify(sprintf('+%d seconds', $ttlSeconds)));

        $this->tokens[$siteId] = $token;

        return $token;
    }
}

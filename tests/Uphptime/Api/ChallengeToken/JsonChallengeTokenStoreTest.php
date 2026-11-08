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
use PHPUnit\Framework\TestCase;
use Uphptime\Api\ChallengeToken\JsonChallengeTokenStore;
use Uphptime\Support\AtomicWriter;

final class JsonChallengeTokenStoreTest extends TestCase
{
    private string $directory;

    public function testCurrentReturnsNullForASiteThatNeverHadATokenIssued(): void
    {
        $store = new JsonChallengeTokenStore(new AtomicWriter(), $this->directory);

        self::assertNull($store->current('site-a', new DateTimeImmutable('2026-01-01T00:00:00+00:00')));
    }

    public function testCurrentReturnsNullOnceTheTokenHasExpired(): void
    {
        $store    = new JsonChallengeTokenStore(new AtomicWriter(), $this->directory);
        $issuedAt = new DateTimeImmutable('2026-01-01T00:00:00+00:00');

        $store->issue('site-a', $issuedAt, 60);

        self::assertNull($store->current('site-a', $issuedAt->modify('+61 seconds')));
    }

    public function testIssueThenCurrentReturnsTheSameToken(): void
    {
        $store    = new JsonChallengeTokenStore(new AtomicWriter(), $this->directory);
        $issuedAt = new DateTimeImmutable('2026-01-01T00:00:00+00:00');

        $issued = $store->issue('site-a', $issuedAt, 300);
        $read   = $store->current('site-a', $issuedAt->modify('+30 seconds'));

        self::assertNotNull($read);
        self::assertSame($issued->token, $read->token);
        self::assertSame('site-a', $read->siteId);
    }

    public function testReissuingReplacesThePreviousToken(): void
    {
        $store    = new JsonChallengeTokenStore(new AtomicWriter(), $this->directory);
        $issuedAt = new DateTimeImmutable('2026-01-01T00:00:00+00:00');

        $first  = $store->issue('site-a', $issuedAt, 300);
        $second = $store->issue('site-a', $issuedAt, 300);
        $read   = $store->current('site-a', $issuedAt);

        self::assertNotSame($first->token, $second->token);
        self::assertSame($second->token, $read?->token);
    }

    protected function setUp(): void
    {
        $this->directory = sys_get_temp_dir() . '/uphptime-challenge-tokens-' . bin2hex(random_bytes(4));
    }

    protected function tearDown(): void
    {
        foreach (glob($this->directory . '/*') ?: [] as $file) {
            @unlink($file);
        }
        @rmdir($this->directory);
    }
}

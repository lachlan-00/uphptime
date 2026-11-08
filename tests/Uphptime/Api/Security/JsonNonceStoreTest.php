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

namespace Uphptime\Tests\Api\Security;

use DateTimeImmutable;
use PHPUnit\Framework\TestCase;
use Uphptime\Api\Security\JsonNonceStore;
use Uphptime\Support\AtomicWriter;

final class JsonNonceStoreTest extends TestCase
{
    private string $directory;

    public function testDuplicateNonceForTheSameKeyIsRejected(): void
    {
        $store = new JsonNonceStore(new AtomicWriter(), $this->directory);
        $now   = new DateTimeImmutable('2026-01-01T00:00:00+00:00');

        self::assertTrue($store->recordIfNew('k1', 'nonce-a', $now, 60));
        self::assertFalse($store->recordIfNew('k1', 'nonce-a', $now, 60), 'a replayed nonce must be rejected');
    }

    public function testExpiredEntriesArePrunedSoTheFileStaysBounded(): void
    {
        $store = new JsonNonceStore(new AtomicWriter(), $this->directory);
        $t0    = new DateTimeImmutable('2026-01-01T00:00:00+00:00');

        $store->recordIfNew('k1', 'old-nonce', $t0, 60);

        $t1 = $t0->modify('+120 seconds');
        self::assertTrue(
            $store->recordIfNew('k1', 'old-nonce', $t1, 60),
            'a nonce outside the retention window must be forgotten, not treated as a permanent replay record',
        );
    }

    public function testSameNonceIsIndependentlyValidForTwoDifferentKeyIds(): void
    {
        $store = new JsonNonceStore(new AtomicWriter(), $this->directory);
        $now   = new DateTimeImmutable('2026-01-01T00:00:00+00:00');

        self::assertTrue($store->recordIfNew('k1', 'shared-nonce', $now, 60));
        self::assertTrue($store->recordIfNew('k2', 'shared-nonce', $now, 60));
    }

    protected function setUp(): void
    {
        $this->directory = sys_get_temp_dir() . '/uphptime-nonces-' . bin2hex(random_bytes(4));
        mkdir($this->directory, 0775, true);
    }

    protected function tearDown(): void
    {
        foreach (glob($this->directory . '/*') ?: [] as $file) {
            unlink($file);
        }
        rmdir($this->directory);
    }
}

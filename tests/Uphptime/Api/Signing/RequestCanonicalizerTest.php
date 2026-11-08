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

namespace Uphptime\Tests\Api\Signing;

use PHPUnit\Framework\TestCase;
use Uphptime\Api\Signing\RequestCanonicalizer;

final class RequestCanonicalizerTest extends TestCase
{
    public function testBodyHashIsComputedFromRawBytesNotTrusted(): void
    {
        $canonicalizer = new RequestCanonicalizer();

        $a = $canonicalizer->canonicalize('POST', '/p', 'k', '1', 'n', 'r', 'one body');
        $b = $canonicalizer->canonicalize('POST', '/p', 'k', '1', 'n', 'r', 'different body');

        self::assertNotSame($a, $b);
    }

    public function testCanonicalStringMatchesFixedFieldOrderAndFormat(): void
    {
        $canonicalizer = new RequestCanonicalizer();

        $canonical = $canonicalizer->canonicalize('post', '/peer/v1/check-site', 'k1', '1700000000', 'nonce123', 'req-1', '{"a":1}');

        $expected = implode("\n", [
            'POST',
            '/peer/v1/check-site',
            'k1',
            '1700000000',
            'nonce123',
            'req-1',
            hash('sha256', '{"a":1}'),
        ]);

        self::assertSame($expected, $canonical);
    }
}

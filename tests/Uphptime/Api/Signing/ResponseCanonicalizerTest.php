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
use Uphptime\Api\Signing\ResponseCanonicalizer;

final class ResponseCanonicalizerTest extends TestCase
{
    public function testCanonicalStringMatchesFixedFieldOrderAndFormat(): void
    {
        $canonicalizer = new ResponseCanonicalizer();

        $canonical = $canonicalizer->canonicalize('req-1', '1700000000', 200, '{"outcome":"pass"}');

        $expected = implode("\n", [
            'req-1',
            '1700000000',
            '200',
            hash('sha256', '{"outcome":"pass"}'),
        ]);

        self::assertSame($expected, $canonical);
    }

    public function testDifferentRequestIdsProduceDifferentCanonicalStrings(): void
    {
        $canonicalizer = new ResponseCanonicalizer();

        $a = $canonicalizer->canonicalize('req-1', '1700000000', 200, 'body');
        $b = $canonicalizer->canonicalize('req-2', '1700000000', 200, 'body');

        self::assertNotSame($a, $b, 'binding to the request ID is what stops a response being replayed against a different request');
    }
}

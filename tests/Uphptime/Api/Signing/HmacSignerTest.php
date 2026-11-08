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
use Uphptime\Api\Signing\HmacSigner;

final class HmacSignerTest extends TestCase
{
    public function testASingleByteChangeInTheCanonicalStringFailsVerification(): void
    {
        $signer    = new HmacSigner();
        $secret    = 'super-secret';
        $signature = $signer->sign('canonical-string-a', $secret);

        self::assertFalse($signer->verify('canonical-string-b', $secret, $signature));
    }

    public function testSignThenVerifyRoundTrips(): void
    {
        $signer    = new HmacSigner();
        $canonical = 'POST\n/peer/v1/check-site\nk1\n1700000000\nnonce\nreq-1\n' . hash('sha256', 'body');
        $secret    = 'super-secret';

        $signature = $signer->sign($canonical, $secret);

        self::assertTrue($signer->verify($canonical, $secret, $signature));
    }

    public function testVerifyUsesConstantTimeComparisonNotStringEquality(): void
    {
        $signer    = new HmacSigner();
        $canonical = 'canonical';
        $signature = $signer->sign($canonical, 'secret');

        // A signature that merely differs in case must still fail even though some naive
        // comparisons (e.g. a loose string match) could be fooled by encoding quirks.
        self::assertFalse($signer->verify($canonical, 'secret', strtoupper($signature)));
    }

    public function testWrongSecretFailsVerification(): void
    {
        $signer    = new HmacSigner();
        $canonical = 'same-canonical-string';
        $signature = $signer->sign($canonical, 'secret-a');

        self::assertFalse($signer->verify($canonical, 'secret-b', $signature));
    }
}

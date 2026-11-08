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

namespace Uphptime\Tests\Cli;

use InvalidArgumentException;
use PHPUnit\Framework\TestCase;
use Uphptime\Cli\PeerKeyOptionResolver;
use Uphptime\Tests\Support\FakeClock;

final class PeerKeyOptionResolverTest extends TestCase
{
    /** @return array<string, array{string, bool, bool}> */
    public static function directionProvider(): array
    {
        return [
            'both' => ['both', true, true],
            'outbound' => ['outbound', true, false],
            'inbound' => ['inbound', false, true],
        ];
    }

    public function testAcceptingGivenKeyAndSecretReturnsThemWithNoGeneratedSecret(): void
    {
        $resolver = new PeerKeyOptionResolver(new FakeClock());
        $secret   = base64_encode('0123456789012345678901234567890v');

        $result = $resolver->resolve(['key-id' => 'k-1', 'secret' => $secret]);

        self::assertSame('k-1', $result['key']->keyId);
        self::assertSame(base64_decode($secret, true), $result['key']->secret);
        self::assertNull($result['generatedSecretBase64']);
    }

    public function testGeneratingReturnsAKeyAndANonNullSecret(): void
    {
        $resolver = new PeerKeyOptionResolver(new FakeClock());

        $result = $resolver->resolve([]);

        self::assertNotEmpty($result['key']->keyId);
        self::assertNotEmpty($result['key']->secret);
        self::assertNotNull($result['generatedSecretBase64']);
        self::assertSame($result['key']->secret, base64_decode($result['generatedSecretBase64'], true));
    }

    /** @dataProvider directionProvider */
    public function testKeyDirectionMapsToTheCorrectFlags(string $direction, bool $expectedOutbound, bool $expectedInbound): void
    {
        $resolver = new PeerKeyOptionResolver(new FakeClock());

        $result = $resolver->resolve(['key-direction' => $direction]);

        self::assertSame($expectedOutbound, $result['key']->outbound);
        self::assertSame($expectedInbound, $result['key']->inbound);
    }

    public function testKeyIdWithoutSecretThrows(): void
    {
        $resolver = new PeerKeyOptionResolver(new FakeClock());

        $this->expectException(InvalidArgumentException::class);
        $resolver->resolve(['key-id' => 'k-1']);
    }

    public function testSecretWithoutKeyIdThrows(): void
    {
        $resolver = new PeerKeyOptionResolver(new FakeClock());

        $this->expectException(InvalidArgumentException::class);
        $resolver->resolve(['secret' => base64_encode('x')]);
    }
}

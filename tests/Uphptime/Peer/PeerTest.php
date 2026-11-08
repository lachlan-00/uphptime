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

namespace Uphptime\Tests\Peer;

use DateTimeImmutable;
use PHPUnit\Framework\TestCase;
use Uphptime\Peer\Peer;
use Uphptime\Peer\PeerKey;

final class PeerTest extends TestCase
{
    public function testActiveOutboundKeyIdIgnoresDisabledAndInboundOnlyKeys(): void
    {
        $peer = $this->peerWithKeys([
            $this->key('old', outbound: false, inbound: true, enabled: true),
            $this->key('disabled', outbound: true, inbound: true, enabled: false),
            $this->key('current', outbound: true, inbound: true, enabled: true),
        ]);

        self::assertSame('current', $peer->activeOutboundKeyId());
    }

    public function testFindInboundKeyIgnoresOutboundOnlyAndDisabledKeys(): void
    {
        $peer = $this->peerWithKeys([
            $this->key('out-only', outbound: true, inbound: false, enabled: true),
            $this->key('disabled-in', outbound: false, inbound: true, enabled: false),
            $this->key('valid-in', outbound: false, inbound: true, enabled: true),
        ]);

        self::assertNull($peer->findInboundKey('out-only'));
        self::assertNull($peer->findInboundKey('disabled-in'));
        self::assertNotNull($peer->findInboundKey('valid-in'));
    }

    public function testNoActiveOutboundKeyReturnsNull(): void
    {
        $peer = $this->peerWithKeys([$this->key('k', outbound: false, inbound: true, enabled: true)]);

        self::assertNull($peer->activeOutboundKeyId());
    }

    private function key(string $keyId, bool $outbound, bool $inbound, bool $enabled): PeerKey
    {
        return new PeerKey($keyId, 'secret', $outbound, $inbound, $enabled, new DateTimeImmutable('2026-01-01T00:00:00+00:00'));
    }

    /** @param PeerKey[] $keys */
    private function peerWithKeys(array $keys): Peer
    {
        return new Peer('peer-a', 'Peer A', 'https://peer-a.test', true, true, null, [], $keys);
    }
}

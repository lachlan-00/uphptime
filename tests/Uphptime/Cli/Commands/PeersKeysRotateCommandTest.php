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

namespace Uphptime\Tests\Cli\Commands;

use PHPUnit\Framework\TestCase;
use Uphptime\Cli\Commands\PeersAddCommand;
use Uphptime\Cli\Commands\PeersKeysRotateCommand;
use Uphptime\Cli\PeerKeyOptionResolver;
use Uphptime\Peer\Peer;
use Uphptime\Peer\PeerKey;
use Uphptime\Tests\Cli\FakeConsoleOutput;
use Uphptime\Tests\Storage\InMemoryPeerRepository;
use Uphptime\Tests\Support\FakeClock;

final class PeersKeysRotateCommandTest extends TestCase
{
    public function testDemotesThePreviousOutboundKeyWithoutRemovingIt(): void
    {
        $peers    = $this->peersWithOne();
        $oldKeyId = $peers->find('node-b')?->keys[0]->keyId;

        $exitCode = (new PeersKeysRotateCommand($peers, new PeerKeyOptionResolver(new FakeClock()), new FakeConsoleOutput()))
            ->run(['--peer=node-b']);

        self::assertSame(0, $exitCode);
        $peer = $peers->find('node-b');
        self::assertCount(2, $peer?->keys ?? []);
        $old = $this->findKey($peer, (string) $oldKeyId);
        self::assertNotNull($old, 'the old key is still present');
        self::assertFalse($old->outbound);
        self::assertTrue($old->inbound);
        self::assertNotSame($oldKeyId, $peer?->activeOutboundKeyId());
    }

    public function testRotatingWithNoPriorActiveOutboundKeyStillSucceeds(): void
    {
        $peers = new InMemoryPeerRepository();
        (new PeersAddCommand($peers, new PeerKeyOptionResolver(new FakeClock()), new FakeConsoleOutput()))
            ->run(['--id=node-b', '--label=Node B', '--key-direction=inbound']);
        self::assertNull($peers->find('node-b')?->outboundKey(), 'the peer starts with no outbound key');

        $exitCode = (new PeersKeysRotateCommand($peers, new PeerKeyOptionResolver(new FakeClock()), new FakeConsoleOutput()))
            ->run(['--peer=node-b']);

        self::assertSame(0, $exitCode);
        self::assertCount(2, $peers->find('node-b')?->keys ?? []);
        self::assertNotNull($peers->find('node-b')?->outboundKey());
    }

    public function testUnknownPeerFailsWithNonZeroExit(): void
    {
        $command = new PeersKeysRotateCommand(new InMemoryPeerRepository(), new PeerKeyOptionResolver(new FakeClock()), new FakeConsoleOutput());

        self::assertSame(1, $command->run(['--peer=missing']));
    }

    private function findKey(?Peer $peer, string $keyId): ?PeerKey
    {
        foreach ($peer?->keys ?? [] as $key) {
            if ($key->keyId === $keyId) {
                return $key;
            }
        }

        return null;
    }

    private function peersWithOne(): InMemoryPeerRepository
    {
        $peers = new InMemoryPeerRepository();
        (new PeersAddCommand($peers, new PeerKeyOptionResolver(new FakeClock()), new FakeConsoleOutput()))
            ->run(['--id=node-b', '--label=Node B']);

        return $peers;
    }
}

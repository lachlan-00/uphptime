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
use Uphptime\Cli\Commands\PeersKeysAddCommand;
use Uphptime\Cli\Commands\PeersKeysRetireCommand;
use Uphptime\Cli\PeerKeyOptionResolver;
use Uphptime\Peer\PeerKey;
use Uphptime\Tests\Cli\FakeConsoleOutput;
use Uphptime\Tests\Storage\InMemoryPeerRepository;
use Uphptime\Tests\Support\FakeClock;

final class PeersKeysRetireCommandTest extends TestCase
{
    public function testRetiringTheOnlyRemainingKeyStillSucceeds(): void
    {
        $peers  = $this->peersWithOne();
        $keyId  = $peers->find('node-b')?->keys[0]->keyId;

        $exitCode = (new PeersKeysRetireCommand($peers, new FakeConsoleOutput()))->run(['--peer=node-b', '--key-id=' . $keyId]);

        self::assertSame(0, $exitCode);
        self::assertCount(0, $peers->find('node-b')?->keys ?? []);
    }

    public function testTheNamedKeyIsFullyAbsentAfterwards(): void
    {
        $peers = $this->peersWithOne();
        (new PeersKeysAddCommand($peers, new PeerKeyOptionResolver(new FakeClock()), new FakeConsoleOutput()))
            ->run(['--peer=node-b', '--key-direction=inbound']);
        $firstKeyId = $peers->find('node-b')?->keys[0]->keyId;

        (new PeersKeysRetireCommand($peers, new FakeConsoleOutput()))->run(['--peer=node-b', '--key-id=' . $firstKeyId]);

        $remainingIds = array_map(static fn(PeerKey $key): string => $key->keyId, $peers->find('node-b')?->keys ?? []);
        self::assertNotContains($firstKeyId, $remainingIds);
        self::assertCount(1, $remainingIds);
    }

    public function testUnknownKeyIdFailsWithNonZeroExit(): void
    {
        $peers   = $this->peersWithOne();
        $command = new PeersKeysRetireCommand($peers, new FakeConsoleOutput());

        self::assertSame(1, $command->run(['--peer=node-b', '--key-id=missing']));
    }

    public function testUnknownPeerFailsWithNonZeroExit(): void
    {
        $command = new PeersKeysRetireCommand(new InMemoryPeerRepository(), new FakeConsoleOutput());

        self::assertSame(1, $command->run(['--peer=missing', '--key-id=k-1']));
    }

    private function peersWithOne(): InMemoryPeerRepository
    {
        $peers = new InMemoryPeerRepository();
        (new PeersAddCommand($peers, new PeerKeyOptionResolver(new FakeClock()), new FakeConsoleOutput()))
            ->run(['--id=node-b', '--label=Node B']);

        return $peers;
    }
}

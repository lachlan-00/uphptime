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
use Uphptime\Cli\PeerKeyOptionResolver;
use Uphptime\Tests\Cli\FakeConsoleOutput;
use Uphptime\Tests\Storage\InMemoryPeerRepository;
use Uphptime\Tests\Support\FakeClock;

final class PeersKeysAddCommandTest extends TestCase
{
    public function testAllowMultipleOutboundOverridesTheGuard(): void
    {
        $peers = $this->peersWithOne();

        $exitCode = (new PeersKeysAddCommand($peers, new PeerKeyOptionResolver(new FakeClock()), new FakeConsoleOutput()))
            ->run(['--peer=node-b', '--key-direction=outbound', '--allow-multiple-outbound']);

        self::assertSame(0, $exitCode);
        self::assertCount(2, $peers->find('node-b')?->keys ?? []);
    }

    public function testInboundOnlyAlongsideAnActiveOutboundKeySucceeds(): void
    {
        $peers = $this->peersWithOne();

        $exitCode = (new PeersKeysAddCommand($peers, new PeerKeyOptionResolver(new FakeClock()), new FakeConsoleOutput()))
            ->run(['--peer=node-b', '--key-direction=inbound']);

        self::assertSame(0, $exitCode);
        self::assertCount(2, $peers->find('node-b')?->keys ?? []);
    }

    public function testRequestingAnotherActiveOutboundKeyFailsWithNonZeroExit(): void
    {
        $peers   = $this->peersWithOne();
        $command = new PeersKeysAddCommand($peers, new PeerKeyOptionResolver(new FakeClock()), new FakeConsoleOutput());

        self::assertSame(1, $command->run(['--peer=node-b', '--key-direction=outbound']));
        self::assertCount(1, $peers->find('node-b')?->keys ?? []);
    }

    public function testUnknownPeerFailsWithNonZeroExit(): void
    {
        $command = new PeersKeysAddCommand(new InMemoryPeerRepository(), new PeerKeyOptionResolver(new FakeClock()), new FakeConsoleOutput());

        self::assertSame(1, $command->run(['--peer=missing']));
    }

    private function peersWithOne(): InMemoryPeerRepository
    {
        $peers = new InMemoryPeerRepository();
        (new PeersAddCommand($peers, new PeerKeyOptionResolver(new FakeClock()), new FakeConsoleOutput()))
            ->run(['--id=node-b', '--label=Node B']);

        return $peers;
    }
}

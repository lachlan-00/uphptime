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
use Uphptime\Cli\Commands\PeersAllowlistAddCommand;
use Uphptime\Cli\PeerKeyOptionResolver;
use Uphptime\Tests\Cli\FakeConsoleOutput;
use Uphptime\Tests\Storage\InMemoryPeerRepository;
use Uphptime\Tests\Support\FakeClock;

final class PeersAllowlistAddCommandTest extends TestCase
{
    public function testAppendsAPattern(): void
    {
        $peers = $this->peersWithOne();

        (new PeersAllowlistAddCommand($peers, new FakeConsoleOutput()))->run(['--id=node-b', '--pattern=example.com']);

        self::assertSame(['example.com'], $peers->find('node-b')?->targetAllowlist);
    }

    public function testDedupesARepeatedPattern(): void
    {
        $peers   = $this->peersWithOne();
        $command = new PeersAllowlistAddCommand($peers, new FakeConsoleOutput());
        $command->run(['--id=node-b', '--pattern=example.com']);

        $command->run(['--id=node-b', '--pattern=example.com']);

        self::assertSame(['example.com'], $peers->find('node-b')?->targetAllowlist);
    }

    public function testUnknownPeerFailsWithNonZeroExit(): void
    {
        $command = new PeersAllowlistAddCommand(new InMemoryPeerRepository(), new FakeConsoleOutput());

        self::assertSame(1, $command->run(['--id=missing', '--pattern=example.com']));
    }

    private function peersWithOne(): InMemoryPeerRepository
    {
        $peers = new InMemoryPeerRepository();
        (new PeersAddCommand($peers, new PeerKeyOptionResolver(new FakeClock()), new FakeConsoleOutput()))
            ->run(['--id=node-b', '--label=Node B']);

        return $peers;
    }
}

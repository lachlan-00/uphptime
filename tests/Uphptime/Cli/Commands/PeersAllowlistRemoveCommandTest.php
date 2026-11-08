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
use Uphptime\Cli\Commands\PeersAllowlistRemoveCommand;
use Uphptime\Cli\PeerKeyOptionResolver;
use Uphptime\Tests\Cli\FakeConsoleOutput;
use Uphptime\Tests\Storage\InMemoryPeerRepository;
use Uphptime\Tests\Support\FakeClock;

final class PeersAllowlistRemoveCommandTest extends TestCase
{
    public function testDropsAPresentPattern(): void
    {
        $peers  = $this->peersWithOnePattern();
        $output = new FakeConsoleOutput();

        $exitCode = (new PeersAllowlistRemoveCommand($peers, $output))->run(['--id=node-b', '--pattern=example.com']);

        self::assertSame(0, $exitCode);
        self::assertSame([], $peers->find('node-b')?->targetAllowlist);
        self::assertTrue($output->lastLineDecoded()['removed']);
    }

    public function testNoOpsAnAbsentPatternWithoutError(): void
    {
        $peers  = $this->peersWithOnePattern();
        $output = new FakeConsoleOutput();

        $exitCode = (new PeersAllowlistRemoveCommand($peers, $output))->run(['--id=node-b', '--pattern=not-there.com']);

        self::assertSame(0, $exitCode);
        self::assertSame(['example.com'], $peers->find('node-b')?->targetAllowlist);
        self::assertFalse($output->lastLineDecoded()['removed']);
    }

    public function testUnknownPeerFailsWithNonZeroExit(): void
    {
        $command = new PeersAllowlistRemoveCommand(new InMemoryPeerRepository(), new FakeConsoleOutput());

        self::assertSame(1, $command->run(['--id=missing', '--pattern=example.com']));
    }

    private function peersWithOnePattern(): InMemoryPeerRepository
    {
        $peers = new InMemoryPeerRepository();
        (new PeersAddCommand($peers, new PeerKeyOptionResolver(new FakeClock()), new FakeConsoleOutput()))
            ->run(['--id=node-b', '--label=Node B']);
        (new PeersAllowlistAddCommand($peers, new FakeConsoleOutput()))->run(['--id=node-b', '--pattern=example.com']);

        return $peers;
    }
}

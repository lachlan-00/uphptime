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
use Uphptime\Cli\PeerKeyOptionResolver;
use Uphptime\Tests\Cli\FakeConsoleOutput;
use Uphptime\Tests\Storage\InMemoryPeerRepository;
use Uphptime\Tests\Support\FakeClock;

final class PeersAddCommandTest extends TestCase
{
    public function testDuplicateIdFailsWithNonZeroExit(): void
    {
        $peers   = new InMemoryPeerRepository();
        $command = new PeersAddCommand($peers, new PeerKeyOptionResolver(new FakeClock()), new FakeConsoleOutput());
        $command->run(['--id=node-b', '--label=Node B']);

        $exitCode = $command->run(['--id=node-b', '--label=Node B Again']);

        self::assertSame(1, $exitCode);
        self::assertCount(1, $peers->all());
    }

    public function testHappyPathWithAGeneratedSecret(): void
    {
        $peers   = new InMemoryPeerRepository();
        $output  = new FakeConsoleOutput();
        $command = new PeersAddCommand($peers, new PeerKeyOptionResolver(new FakeClock()), $output);

        $exitCode = $command->run(['--id=node-b', '--label=Node B', '--base-url=https://node-b.example']);

        self::assertSame(0, $exitCode);
        $peer = $peers->find('node-b');
        self::assertNotNull($peer);
        self::assertSame('https://node-b.example', $peer->baseUrl);
        self::assertCount(1, $peer->keys);
        self::assertNotNull($output->lastLineDecoded()['secret_to_relay']);
    }

    public function testHappyPathWithAGivenSecretDoesNotRelayANewOne(): void
    {
        $peers   = new InMemoryPeerRepository();
        $output  = new FakeConsoleOutput();
        $command = new PeersAddCommand($peers, new PeerKeyOptionResolver(new FakeClock()), $output);
        $secret  = base64_encode(str_repeat('a', 32));

        $exitCode = $command->run(['--id=node-b', '--label=Node B', '--key-id=k-1', '--secret=' . $secret]);

        self::assertSame(0, $exitCode);
        self::assertSame('k-1', $peers->find('node-b')?->keys[0]->keyId);
        self::assertNull($output->lastLineDecoded()['secret_to_relay']);
    }

    public function testMissingRequiredOptionFailsWithNonZeroExit(): void
    {
        $peers   = new InMemoryPeerRepository();
        $command = new PeersAddCommand($peers, new PeerKeyOptionResolver(new FakeClock()), new FakeConsoleOutput());

        $exitCode = $command->run(['--id=node-b']);

        self::assertSame(1, $exitCode);
        self::assertCount(0, $peers->all());
    }
}

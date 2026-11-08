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
use Uphptime\Cli\Commands\PeersRemoveCommand;
use Uphptime\Cli\PeerKeyOptionResolver;
use Uphptime\Peer\PeerReputation;
use Uphptime\Tests\Cli\FakeConsoleOutput;
use Uphptime\Tests\Storage\InMemoryPeerRepository;
use Uphptime\Tests\Storage\InMemoryPeerReputationRepository;
use Uphptime\Tests\Support\FakeClock;

final class PeersRemoveCommandTest extends TestCase
{
    public function testRemoveKeepsReputationByDefault(): void
    {
        $peers = new InMemoryPeerRepository();
        (new PeersAddCommand($peers, new PeerKeyOptionResolver(new FakeClock()), new FakeConsoleOutput()))->run(['--id=node-b', '--label=Node B']);
        $reputations = new InMemoryPeerReputationRepository();
        $reputations->save(PeerReputation::neutral('node-b'));

        $command = new PeersRemoveCommand($peers, $reputations, new FakeConsoleOutput());
        self::assertSame(0, $command->run(['--id=node-b']));
        self::assertNull($peers->find('node-b'));
        self::assertNotEmpty($reputations->all(), 'reputation is kept by default');
    }

    public function testRemoveWithPurgeFlagDeletesReputation(): void
    {
        $peers = new InMemoryPeerRepository();
        (new PeersAddCommand($peers, new PeerKeyOptionResolver(new FakeClock()), new FakeConsoleOutput()))->run(['--id=node-b', '--label=Node B']);
        $reputations = new InMemoryPeerReputationRepository();
        $reputations->save(PeerReputation::neutral('node-b'));

        $command = new PeersRemoveCommand($peers, $reputations, new FakeConsoleOutput());
        self::assertSame(0, $command->run(['--id=node-b', '--purge-reputation']));
        self::assertEmpty($reputations->all(), '--purge-reputation deletes the peer\'s reputation');
    }

    public function testUnknownIdFailsWithNonZeroExit(): void
    {
        $command = new PeersRemoveCommand(new InMemoryPeerRepository(), new InMemoryPeerReputationRepository(), new FakeConsoleOutput());

        self::assertSame(1, $command->run(['--id=missing']));
    }
}

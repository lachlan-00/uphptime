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

use DateTimeImmutable;
use PHPUnit\Framework\TestCase;
use Uphptime\Cli\Commands\PeersAddCommand;
use Uphptime\Cli\Commands\PeersReputationResetCommand;
use Uphptime\Cli\PeerKeyOptionResolver;
use Uphptime\Config\Config;
use Uphptime\Peer\PeerReputation;
use Uphptime\Tests\Cli\FakeConsoleOutput;
use Uphptime\Tests\Storage\InMemoryPeerRepository;
use Uphptime\Tests\Storage\InMemoryPeerReputationRepository;
use Uphptime\Tests\Support\FakeClock;

final class PeersReputationResetCommandTest extends TestCase
{
    public function testAnExcludedLowScorePeerBecomesFullyNeutral(): void
    {
        $peers = new InMemoryPeerRepository();
        (new PeersAddCommand($peers, new PeerKeyOptionResolver(new FakeClock()), new FakeConsoleOutput()))->run(['--id=node-b', '--label=Node B']);
        $reputations = new InMemoryPeerReputationRepository();
        $reputations->save(new PeerReputation('node-b', 10.0, 3, new DateTimeImmutable('2026-01-01T01:00:00+00:00'), new DateTimeImmutable('2026-01-01T00:00:00+00:00'), 9));
        $config = new Config('data', 300, 90, 10, 240, 900, 'uphptime-test', 'UTC');

        $exitCode = (new PeersReputationResetCommand($peers, $reputations, $config, new FakeConsoleOutput()))->run(['--id=node-b']);

        self::assertSame(0, $exitCode);
        $reputation = $reputations->get('node-b');
        self::assertSame(100.0, $reputation->score);
        self::assertSame(0, $reputation->consecutiveExclusions);
        self::assertSame(0, $reputation->totalEvents);
        self::assertNull($reputation->excludedUntil);
    }

    public function testUnknownPeerFailsWithNonZeroExit(): void
    {
        $config  = new Config('data', 300, 90, 10, 240, 900, 'uphptime-test', 'UTC');
        $command = new PeersReputationResetCommand(new InMemoryPeerRepository(), new InMemoryPeerReputationRepository(), $config, new FakeConsoleOutput());

        self::assertSame(1, $command->run(['--id=missing']));
    }
}

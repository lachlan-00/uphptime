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
use Uphptime\Cli\Commands\PeersListCommand;
use Uphptime\Cli\PeerKeyOptionResolver;
use Uphptime\Peer\PeerReputation;
use Uphptime\Tests\Cli\FakeConsoleOutput;
use Uphptime\Tests\Storage\InMemoryPeerRepository;
use Uphptime\Tests\Storage\InMemoryPeerReputationRepository;
use Uphptime\Tests\Support\FakeClock;

final class PeersListCommandTest extends TestCase
{
    public function testAnUnscoredPeerShowsNeutralDefaults(): void
    {
        $peers = new InMemoryPeerRepository();
        (new PeersAddCommand($peers, new PeerKeyOptionResolver(new FakeClock()), new FakeConsoleOutput()))->run(['--id=node-b', '--label=Node B']);
        $output = new FakeConsoleOutput();

        (new PeersListCommand($peers, new InMemoryPeerReputationRepository(), new FakeClock(), $output))->run([]);

        $row = $output->lastLineDecoded()[0];
        self::assertSame(100.0, (float) $row['reputation']['score']);
        self::assertFalse($row['reputation']['excluded']);
        self::assertSame(0, $row['reputation']['total_events']);
    }

    public function testAScoredPeerShowsItsActualReputation(): void
    {
        $peers = new InMemoryPeerRepository();
        (new PeersAddCommand($peers, new PeerKeyOptionResolver(new FakeClock()), new FakeConsoleOutput()))->run(['--id=node-b', '--label=Node B']);
        $reputations = new InMemoryPeerReputationRepository();
        $reputations->save(new PeerReputation('node-b', 20.0, 2, new DateTimeImmutable('2026-01-01T01:00:00+00:00'), null, 7));
        $output = new FakeConsoleOutput();

        (new PeersListCommand($peers, $reputations, new FakeClock('2026-01-01T00:00:00+00:00'), $output))->run([]);

        $row = $output->lastLineDecoded()[0];
        self::assertSame(20.0, (float) $row['reputation']['score']);
        self::assertTrue($row['reputation']['excluded']);
        self::assertSame(7, $row['reputation']['total_events']);
    }

    public function testSecretsNeverAppearInTheOutput(): void
    {
        $peers         = new InMemoryPeerRepository();
        $addOutput     = new FakeConsoleOutput();
        $addedSecret   = base64_encode(str_repeat('s', 32));
        (new PeersAddCommand($peers, new PeerKeyOptionResolver(new FakeClock()), $addOutput))
            ->run(['--id=node-b', '--label=Node B', '--key-id=k-1', '--secret=' . $addedSecret]);
        $output = new FakeConsoleOutput();

        (new PeersListCommand($peers, new InMemoryPeerReputationRepository(), new FakeClock(), $output))->run([]);

        self::assertStringNotContainsString($addedSecret, (string) $output->lastLine());
        self::assertStringNotContainsString(str_repeat('s', 32), (string) $output->lastLine());
    }
}

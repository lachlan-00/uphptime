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
use Uphptime\Cli\Commands\PeersSetCommand;
use Uphptime\Cli\PeerKeyOptionResolver;
use Uphptime\Tests\Cli\FakeConsoleOutput;
use Uphptime\Tests\Storage\InMemoryPeerRepository;
use Uphptime\Tests\Support\FakeClock;

final class PeersSetCommandTest extends TestCase
{
    public function testChangingOnlyLabelLeavesEverythingElseIdentical(): void
    {
        $peers  = $this->peersWithOne();
        $before = $peers->find('node-b');

        (new PeersSetCommand($peers, new FakeConsoleOutput()))->run(['--id=node-b', '--label=New Label']);

        $after = $peers->find('node-b');
        self::assertSame('New Label', $after?->label);
        self::assertSame($before?->baseUrl, $after?->baseUrl);
        self::assertSame($before?->enabled, $after?->enabled);
        self::assertSame($before?->allowOutboundDelegation, $after?->allowOutboundDelegation);
        self::assertSame($before?->rateLimitPerMinute, $after?->rateLimitPerMinute);
        self::assertSame($before?->targetAllowlist, $after?->targetAllowlist);
        self::assertSame($before?->keys[0]->keyId, $after?->keys[0]->keyId);
    }

    public function testDisableFlipsOnlyEnabled(): void
    {
        $peers = $this->peersWithOne();

        (new PeersSetCommand($peers, new FakeConsoleOutput()))->run(['--id=node-b', '--disable']);

        self::assertFalse($peers->find('node-b')?->enabled);
    }

    public function testMutuallyExclusivePairFailsWithNonZeroExit(): void
    {
        $peers   = $this->peersWithOne();
        $command = new PeersSetCommand($peers, new FakeConsoleOutput());

        self::assertSame(1, $command->run(['--id=node-b', '--enable', '--disable']));
    }

    public function testNoRateLimitClearsAPreviouslySetValue(): void
    {
        $peers = $this->peersWithOne();
        (new PeersSetCommand($peers, new FakeConsoleOutput()))->run(['--id=node-b', '--rate-limit=30']);
        self::assertSame(30, $peers->find('node-b')?->rateLimitPerMinute);

        (new PeersSetCommand($peers, new FakeConsoleOutput()))->run(['--id=node-b', '--no-rate-limit']);

        self::assertNull($peers->find('node-b')?->rateLimitPerMinute);
    }

    public function testOnlyIdFailsWithNonZeroExit(): void
    {
        $peers   = $this->peersWithOne();
        $command = new PeersSetCommand($peers, new FakeConsoleOutput());

        self::assertSame(1, $command->run(['--id=node-b']));
    }

    public function testUnknownIdFailsWithNonZeroExit(): void
    {
        $command = new PeersSetCommand(new InMemoryPeerRepository(), new FakeConsoleOutput());

        self::assertSame(1, $command->run(['--id=missing', '--disable']));
    }

    private function peersWithOne(): InMemoryPeerRepository
    {
        $peers = new InMemoryPeerRepository();
        (new PeersAddCommand($peers, new PeerKeyOptionResolver(new FakeClock()), new FakeConsoleOutput()))
            ->run(['--id=node-b', '--label=Node B', '--base-url=https://node-b.example']);

        return $peers;
    }
}

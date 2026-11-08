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

namespace Uphptime\Tests\Storage;

use DateTimeImmutable;
use PHPUnit\Framework\TestCase;
use Uphptime\Peer\PeerReputation;
use Uphptime\Storage\JsonPeerReputationRepository;
use Uphptime\Support\AtomicWriter;

final class JsonPeerReputationRepositoryTest extends TestCase
{
    private string $path;

    public function testAnUnscoredPeerIsNeutralAtTheConfiguredScore(): void
    {
        $repository = new JsonPeerReputationRepository(new AtomicWriter(), $this->path, 100.0);

        $reputation = $repository->get('never-seen');

        self::assertSame(100.0, $reputation->score);
        self::assertSame(0, $reputation->totalEvents);
    }

    public function testRemoveDeletesOnlyTheNamedPeersRow(): void
    {
        $repository = new JsonPeerReputationRepository(new AtomicWriter(), $this->path, 100.0);
        $repository->save(new PeerReputation('a', 50.0, 0, null, null, 1));
        $repository->save(new PeerReputation('b', 60.0, 0, null, null, 2));

        $repository->remove('a');

        self::assertCount(1, $repository->all());
        self::assertSame(100.0, $repository->get('a')->score, 'removed peer reads back as a fresh neutral record');
        self::assertSame(60.0, $repository->get('b')->score);
    }

    public function testRoundTripsIncludingANullExcludedUntil(): void
    {
        $repository = new JsonPeerReputationRepository(new AtomicWriter(), $this->path, 100.0);
        $repository->save(new PeerReputation('a', 32.5, 1, new DateTimeImmutable('2026-01-01T01:00:00+00:00'), new DateTimeImmutable('2026-01-01T00:00:00+00:00'), 5));
        $repository->save(new PeerReputation('b', 90.0, 0, null, new DateTimeImmutable('2026-01-01T00:00:00+00:00'), 2));

        $a = $repository->get('a');
        $b = $repository->get('b');

        self::assertSame(32.5, $a->score);
        self::assertEquals(new DateTimeImmutable('2026-01-01T01:00:00+00:00'), $a->excludedUntil);
        self::assertNull($b->excludedUntil);
        self::assertCount(2, $repository->all());
    }

    public function testSaveOverwritesAnExistingPeersRow(): void
    {
        $repository = new JsonPeerReputationRepository(new AtomicWriter(), $this->path, 100.0);
        $repository->save(new PeerReputation('a', 50.0, 0, null, null, 1));
        $repository->save(new PeerReputation('a', 20.0, 1, new DateTimeImmutable('2026-01-01T01:00:00+00:00'), null, 2));

        self::assertCount(1, $repository->all());
        self::assertSame(20.0, $repository->get('a')->score);
    }

    protected function setUp(): void
    {
        $this->path = sys_get_temp_dir() . '/uphptime-peer-reputation-' . bin2hex(random_bytes(4)) . '.json';
    }

    protected function tearDown(): void
    {
        @unlink($this->path);
        @unlink($this->path . '.lock');
    }
}

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

namespace Uphptime\Tests\Storage\Pdo;

use DateTimeImmutable;
use PDO;
use PHPUnit\Framework\TestCase;
use Uphptime\Peer\PeerReputation;
use Uphptime\Storage\Pdo\PdoPeerReputationRepository;

/**
 * Dialect-agnostic coverage for PdoPeerReputationRepository, run against both real engines.
 */
abstract class PdoPeerReputationRepositoryTestCase extends TestCase
{
    use PdoRepositoryTestHelpers;

    private PDO $pdo;

    public function testGetReturnsNeutralForAnUnscoredPeer(): void
    {
        $reputation = $this->repository()->get('never-scored');

        self::assertSame(100.0, $reputation->score);
        self::assertSame(0, $reputation->consecutiveExclusions);
        self::assertNull($reputation->excludedUntil);
        self::assertSame(0, $reputation->totalEvents);
    }

    public function testRemove(): void
    {
        $repository = $this->repository();
        $repository->save(new PeerReputation('a', 50.0, 1, null, null, 3));

        $repository->remove('a');

        self::assertSame(100.0, $repository->get('a')->score);
    }

    public function testSaveIsABlindOverwriteWithExcludedUntilSetAndNull(): void
    {
        $repository    = $this->repository();
        $excludedUntil = new DateTimeImmutable('2026-01-01T01:00:00+00:00');

        $repository->save(new PeerReputation('a', 32.5, 2, $excludedUntil, new DateTimeImmutable('2026-01-01T00:00:00+00:00'), 7));

        $found = $repository->get('a');
        self::assertSame(32.5, $found->score);
        self::assertSame(2, $found->consecutiveExclusions);
        self::assertNotNull($found->excludedUntil);
        self::assertSame(7, $found->totalEvents);

        $repository->save(new PeerReputation('a', 100.0, 0, null, new DateTimeImmutable('2026-01-01T02:00:00+00:00'), 8));

        $cleared = $repository->get('a');
        self::assertNull($cleared->excludedUntil);
        self::assertSame(100.0, $cleared->score);
    }

    abstract protected function dialectName(): string;

    protected function setUp(): void
    {
        $this->pdo = $this->connectOrSkip($this->dialectName());
    }

    private function repository(): PdoPeerReputationRepository
    {
        return new PdoPeerReputationRepository($this->pdo, $this->dialectFor($this->dialectName()), 100.0);
    }
}

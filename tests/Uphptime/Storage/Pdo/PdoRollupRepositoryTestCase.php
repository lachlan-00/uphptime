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
use Uphptime\History\DailyRollup;
use Uphptime\Storage\Pdo\PdoRollupRepository;

/**
 * Dialect-agnostic coverage for PdoRollupRepository, run against both real engines.
 */
abstract class PdoRollupRepositoryTestCase extends TestCase
{
    use PdoRepositoryTestHelpers;

    private PDO $pdo;

    public function testDatesWithRollupReturnsOldestFirst(): void
    {
        $repository = $this->repository();

        $repository->write($this->rollup('site-a', '2026-01-03'));
        $repository->write($this->rollup('site-a', '2026-01-01'));
        $repository->write($this->rollup('site-a', '2026-01-02'));

        self::assertSame(['2026-01-01', '2026-01-02', '2026-01-03'], $repository->datesWithRollup('site-a'));
    }

    public function testDeleteRemovesOnlyThatSiteAndDay(): void
    {
        $repository = $this->repository();

        $repository->write($this->rollup('site-a', '2026-01-01'));
        $repository->write($this->rollup('site-b', '2026-01-01'));

        $repository->delete('site-a', '2026-01-01');

        self::assertFalse($repository->exists('site-a', '2026-01-01'));
        self::assertTrue($repository->exists('site-b', '2026-01-01'));
    }

    public function testExistsAndRead(): void
    {
        $repository = $this->repository();

        self::assertFalse($repository->exists('site-a', '2026-01-01'));
        self::assertNull($repository->read('site-a', '2026-01-01'));

        $repository->write($this->rollup('site-a', '2026-01-01'));

        self::assertTrue($repository->exists('site-a', '2026-01-01'));
        $read = $repository->read('site-a', '2026-01-01');
        self::assertNotNull($read);
        self::assertSame(288, $read->checksTotal);
        self::assertSame(99.3056, $read->uptimePercent);
    }

    public function testWriteOnExistingKeyReplaces(): void
    {
        $repository = $this->repository();

        $repository->write($this->rollup('site-a', '2026-01-01', checksTotal: 288));
        $repository->write($this->rollup('site-a', '2026-01-01', checksTotal: 100));

        $read = $repository->read('site-a', '2026-01-01');
        self::assertNotNull($read);
        self::assertSame(100, $read->checksTotal);
    }

    abstract protected function dialectName(): string;

    protected function setUp(): void
    {
        $this->pdo = $this->connectOrSkip($this->dialectName());
    }

    private function repository(): PdoRollupRepository
    {
        return new PdoRollupRepository($this->pdo, $this->dialectFor($this->dialectName()));
    }

    private function rollup(string $siteId, string $date, int $checksTotal = 288): DailyRollup
    {
        $closedAt = new DateTimeImmutable($date . 'T00:01:00+00:00');

        return new DailyRollup(
            siteId: $siteId,
            date: $date,
            checksTotal: $checksTotal,
            checksPass: 286,
            checksFail: 2,
            checksUnknown: 0,
            checksExcludedMaintenance: 0,
            checksSelfSourced: $checksTotal,
            checksPeerSourced: 0,
            uptimePercent: 99.3056,
            firstCheckAt: new DateTimeImmutable($date . 'T00:00:12+00:00'),
            lastCheckAt: new DateTimeImmutable($date . 'T23:55:03+00:00'),
            closedAt: $closedAt,
        );
    }
}

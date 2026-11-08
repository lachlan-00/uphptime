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
use Uphptime\Check\CheckOutcome;
use Uphptime\History\HistoryRecord;
use Uphptime\History\HistoryRecordRole;
use Uphptime\Site\CheckType;
use Uphptime\Site\SiteState;
use Uphptime\Storage\Pdo\PdoHistoryRepository;

/**
 * Dialect-agnostic coverage for PdoHistoryRepository, run against both real engines.
 */
abstract class PdoHistoryRepositoryTestCase extends TestCase
{
    use PdoRepositoryTestHelpers;

    private PDO $pdo;

    public function testAppendAndReadDayRoundTripsANestedEvidenceStructure(): void
    {
        $repository = $this->repository();
        $ts         = new DateTimeImmutable('2026-01-02T03:04:05.123456+00:00');

        $evidence = [
            'resolved_ip' => '93.184.216.34',
            'timing_ms' => ['dns' => 5, 'connect' => 12, 'tls' => 20, 'first_byte' => 40, 'total' => 54],
            'tls_fingerprint_sha256' => 'ab12cd34',
        ];

        $repository->append('site-a', $this->record($ts, outcome: CheckOutcome::Pass, evidence: $evidence));

        $records = $repository->readDay('site-a', '2026-01-02');

        self::assertCount(1, $records);
        // Content, not key order: neither MySQL's JSON nor PostgreSQL's JSONB column type preserves it.
        self::assertEquals($evidence, $records[0]->evidence);
        self::assertSame(CheckOutcome::Pass, $records[0]->outcome);
    }

    public function testDatesWithHistoryReturnsOldestFirst(): void
    {
        $repository = $this->repository();

        $repository->append('site-a', $this->record(new DateTimeImmutable('2026-01-03T00:00:00+00:00')));
        $repository->append('site-a', $this->record(new DateTimeImmutable('2026-01-01T00:00:00+00:00')));
        $repository->append('site-a', $this->record(new DateTimeImmutable('2026-01-02T00:00:00+00:00')));

        self::assertSame(['2026-01-01', '2026-01-02', '2026-01-03'], $repository->datesWithHistory('site-a'));
    }

    public function testDeleteDayOnlyRemovesThatSiteAndDay(): void
    {
        $repository = $this->repository();

        $repository->append('site-a', $this->record(new DateTimeImmutable('2026-01-01T00:00:00+00:00')));
        $repository->append('site-a', $this->record(new DateTimeImmutable('2026-01-02T00:00:00+00:00')));
        $repository->append('site-b', $this->record(new DateTimeImmutable('2026-01-01T00:00:00+00:00')));

        $repository->deleteDay('site-a', '2026-01-01');

        self::assertCount(0, $repository->readDay('site-a', '2026-01-01'));
        self::assertCount(1, $repository->readDay('site-a', '2026-01-02'));
        self::assertCount(1, $repository->readDay('site-b', '2026-01-01'));
    }

    public function testPeerIdentityRoundTrips(): void
    {
        $repository = $this->repository();
        $record     = HistoryRecord::fromArray(array_merge(
            $this->record(new DateTimeImmutable('2026-01-01T00:00:00+00:00'))->toArray(),
            ['source' => 'peer:k1', 'peer_id' => 'node-1', 'peer_label' => 'Sydney peer'],
        ));
        $repository->append('site-a', $record);

        $records = $repository->readDay('site-a', '2026-01-01');

        self::assertSame('peer:k1', $records[0]->source);
        self::assertSame('node-1', $records[0]->peerId);
        self::assertSame('Sydney peer', $records[0]->peerLabel);
    }

    public function testRoleAndFlaggedRoundTrip(): void
    {
        $repository = $this->repository();
        $ts         = new DateTimeImmutable('2026-01-01T00:00:00+00:00');

        $repository->append('site-a', $this->record($ts, role: HistoryRecordRole::TieBreak, flagged: true));

        $records = $repository->readDay('site-a', '2026-01-01');

        self::assertSame(HistoryRecordRole::TieBreak, $records[0]->role);
        self::assertTrue($records[0]->flagged);
    }

    abstract protected function dialectName(): string;

    protected function setUp(): void
    {
        $this->pdo = $this->connectOrSkip($this->dialectName());
    }

    /** @param array<string, mixed>|null $evidence */
    private function record(
        DateTimeImmutable $ts,
        CheckOutcome $outcome = CheckOutcome::Pass,
        ?array $evidence = null,
        HistoryRecordRole $role = HistoryRecordRole::Primary,
        bool $flagged = false,
    ): HistoryRecord {
        return new HistoryRecord(
            ts: $ts,
            siteId: 'placeholder',
            checkType: CheckType::Http,
            source: 'self',
            outcome: $outcome,
            httpStatus: 200,
            responseTimeMs: 42,
            keywordMatched: null,
            error: null,
            stateBefore: SiteState::Up,
            stateAfter: SiteState::Up,
            excludedByMaintenance: false,
            evidence: $evidence,
            attestation: 'none',
            role: $role,
            flagged: $flagged,
        );
    }

    private function repository(): PdoHistoryRepository
    {
        return new PdoHistoryRepository($this->pdo, $this->dialectFor($this->dialectName()), 'UTC');
    }
}

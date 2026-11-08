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
use Uphptime\Check\CheckOutcome;
use Uphptime\History\HistoryRecord;
use Uphptime\Site\CheckType;
use Uphptime\Site\SiteState;
use Uphptime\Storage\JsonHistoryRepository;

final class JsonHistoryRepositoryTest extends TestCase
{
    private string $directory;

    public function testAnOldMilestoneOneShapedLineStillParsesWithEvidenceDefaults(): void
    {
        $path = $this->directory . '/site-1';
        mkdir($path, 0775, true);

        $oldShapedLine = json_encode([
            'ts' => '2026-01-01T00:00:00+00:00',
            'site_id' => 'site-1',
            'check_type' => 'http',
            'source' => 'self',
            'outcome' => 'pass',
            'http_status' => 200,
            'response_time_ms' => 10,
            'keyword_matched' => null,
            'error' => null,
            'state_before' => 'unknown',
            'state_after' => 'up',
            'excluded_by_maintenance' => false,
        ]);
        file_put_contents($path . '/2026-01-01.jsonl', $oldShapedLine . "\n");

        $repository = new JsonHistoryRepository($this->directory);
        $records    = $repository->readDay('site-1', '2026-01-01');

        self::assertCount(1, $records);
        self::assertNull($records[0]->evidence);
        self::assertSame('none', $records[0]->attestation);
        self::assertNull($records[0]->peerId);
        self::assertNull($records[0]->peerLabel);
    }

    public function testAppendThenReadDayRoundTrips(): void
    {
        $repository = new JsonHistoryRepository($this->directory);

        $repository->append('site-1', $this->record('site-1', '2026-01-01T00:00:00+00:00'));
        $repository->append('site-1', $this->record('site-1', '2026-01-01T00:05:00+00:00'));

        $records = $repository->readDay('site-1', '2026-01-01');

        self::assertCount(2, $records);
        self::assertSame(CheckOutcome::Pass, $records[0]->outcome);
    }

    public function testConcurrentAppendersProduceExactlyOneLineEach(): void
    {
        $repository  = new JsonHistoryRepository($this->directory);
        $writerCount = 8;

        $script = sys_get_temp_dir() . '/uphptime-history-writer-' . bin2hex(random_bytes(4)) . '.php';
        file_put_contents($script, <<<'PHP'
            <?php
            require $argv[2];
            $repo = new Uphptime\Storage\JsonHistoryRepository($argv[1]);
            $repo->append('site-1', new Uphptime\History\HistoryRecord(
                new DateTimeImmutable('2026-01-01T00:00:00+00:00'),
                'site-1',
                Uphptime\Site\CheckType::Http,
                'self',
                Uphptime\Check\CheckOutcome::Pass,
                200,
                10,
                null,
                null,
                Uphptime\Site\SiteState::Up,
                Uphptime\Site\SiteState::Up,
                false,
            ));
            PHP);

        $autoload  = dirname(__DIR__, 3) . '/vendor/autoload.php';
        $processes = [];
        for ($i = 0; $i < $writerCount; $i++) {
            $processes[] = proc_open(
                [PHP_BINARY, $script, $this->directory, $autoload],
                [1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
                $pipes,
            );
        }

        foreach ($processes as $process) {
            proc_close($process);
        }
        @unlink($script);

        $records = $repository->readDay('site-1', '2026-01-01');

        self::assertCount($writerCount, $records, 'every concurrent append must land as exactly one well-formed line');
    }

    public function testDatesWithHistoryAreSortedOldestFirst(): void
    {
        $repository = new JsonHistoryRepository($this->directory);

        $repository->append('site-1', $this->record('site-1', '2026-01-03T00:00:00+00:00'));
        $repository->append('site-1', $this->record('site-1', '2026-01-01T00:00:00+00:00'));
        $repository->append('site-1', $this->record('site-1', '2026-01-02T00:00:00+00:00'));

        self::assertSame(['2026-01-01', '2026-01-02', '2026-01-03'], $repository->datesWithHistory('site-1'));
    }

    public function testDeleteDayRemovesOnlyThatFile(): void
    {
        $repository = new JsonHistoryRepository($this->directory);

        $repository->append('site-1', $this->record('site-1', '2026-01-01T00:00:00+00:00'));
        $repository->append('site-1', $this->record('site-1', '2026-01-02T00:00:00+00:00'));

        $repository->deleteDay('site-1', '2026-01-01');

        self::assertSame(['2026-01-02'], $repository->datesWithHistory('site-1'));
    }

    public function testEvidenceAndAttestationRoundTripThroughAppendAndRead(): void
    {
        $repository = new JsonHistoryRepository($this->directory);
        $evidence   = ['resolved_ip' => '93.184.216.34', 'timing_ms' => ['total' => 42]];

        $repository->append('site-1', $this->record('site-1', '2026-01-01T00:00:00+00:00', 'peer:k1', $evidence, 'none'));

        $records = $repository->readDay('site-1', '2026-01-01');

        self::assertSame($evidence, $records[0]->evidence);
        self::assertSame('peer:k1', $records[0]->source);
        self::assertSame('none', $records[0]->attestation);
    }

    public function testPeerIdentityRoundTripsThroughHistory(): void
    {
        $repository = new JsonHistoryRepository($this->directory);
        $record     = HistoryRecord::fromArray(array_merge(
            $this->record('site-1', '2026-01-01T00:00:00+00:00', 'peer:k1')->toArray(),
            ['peer_id' => 'node-1', 'peer_label' => 'Sydney peer'],
        ));
        $repository->append('site-1', $record);

        $records = $repository->readDay('site-1', '2026-01-01');

        self::assertSame('node-1', $records[0]->peerId);
        self::assertSame('Sydney peer', $records[0]->peerLabel);
        self::assertSame('peer:k1', $records[0]->source);
    }

    protected function setUp(): void
    {
        $this->directory = sys_get_temp_dir() . '/uphptime-history-' . bin2hex(random_bytes(4));
    }

    protected function tearDown(): void
    {
        if (!is_dir($this->directory)) {
            return;
        }

        foreach (glob($this->directory . '/*/*') ?: [] as $file) {
            unlink($file);
        }
        foreach (glob($this->directory . '/*') ?: [] as $dir) {
            @rmdir($dir);
        }
        @rmdir($this->directory);
    }

    /** @param array<string, mixed>|null $evidence */
    private function record(string $siteId, string $ts, string $source = 'self', ?array $evidence = null, string $attestation = 'none'): HistoryRecord
    {
        return new HistoryRecord(
            ts: new DateTimeImmutable($ts),
            siteId: $siteId,
            checkType: CheckType::Http,
            source: $source,
            outcome: CheckOutcome::Pass,
            httpStatus: 200,
            responseTimeMs: 10,
            keywordMatched: null,
            error: null,
            stateBefore: SiteState::Unknown,
            stateAfter: SiteState::Up,
            excludedByMaintenance: false,
            evidence: $evidence,
            attestation: $attestation,
        );
    }
}

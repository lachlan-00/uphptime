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
use Uphptime\Check\CheckOutcome;
use Uphptime\Cli\Commands\StorageMigrateTimezoneCommand;
use Uphptime\Config\Config;
use Uphptime\History\HistoryRecord;
use Uphptime\Site\CheckType;
use Uphptime\Site\Site;
use Uphptime\Site\SiteRuntimeState;
use Uphptime\Site\SiteState;
use Uphptime\Support\AtomicWriter;
use Uphptime\Tests\Cli\FakeConsoleOutput;
use Uphptime\Tests\Storage\InMemoryRollupRepository;
use Uphptime\Tests\Storage\InMemorySiteRepository;

/**
 * Regression coverage for the duplication bug: a prior version of this command re-read a target
 * date's file from disk and appended it on top of the same records already captured by its own
 * initial scan, doubling every record that didn't change files, and doubling again on every
 * subsequent --force run.
 */
final class StorageMigrateTimezoneCommandTest extends TestCase
{
    private string $dataDirectory;
    private FakeConsoleOutput $output;

    public function testDryRunLeavesFilesUntouched(): void
    {
        $this->writeHistoryLines('site-1', '2026-01-01', [
            $this->recordLine('2026-01-01T01:00:00+00:00'),
        ]);

        $command = $this->buildCommand();
        $exit    = $command->run(['--dry-run']);

        self::assertSame(0, $exit);
        self::assertCount(1, $this->readJsonlLines('site-1', '2026-01-01'), 'dry-run must not modify any file');
    }

    public function testForceCollapsesPreExistingDuplicatesLeftByAnEarlierBuggyRun(): void
    {
        // Simulates damage left by the old buggy command: the same line appears three times.
        $line = $this->recordLine('2026-01-01T01:00:00+00:00');
        $this->writeHistoryLines('site-1', '2026-01-01', [$line, $line, $line]);

        $command = $this->buildCommand();
        $exit    = $command->run(['--force']);

        self::assertSame(0, $exit);
        self::assertCount(1, $this->readJsonlLines('site-1', '2026-01-01'), 'exact duplicates must collapse to one record');

        $output = $this->output->lastLineDecoded();
        self::assertSame(2, $output['records_deduplicated']);
    }

    public function testForceDoesNotDuplicateARecordThatStaysInTheSameDateFile(): void
    {
        // 01:00 UTC + Brisbane's +10:00 stays on the same calendar date - the case that used to double.
        $this->writeHistoryLines('site-1', '2026-01-01', [
            $this->recordLine('2026-01-01T01:00:00+00:00'),
        ]);

        $command = $this->buildCommand();
        $exit    = $command->run(['--force']);

        self::assertSame(0, $exit);
        self::assertCount(1, $this->readJsonlLines('site-1', '2026-01-01'));

        $output = $this->output->lastLineDecoded();
        self::assertSame(1, $output['records_in_place']);
        self::assertSame(0, $output['records_moved']);
        self::assertSame(0, $output['records_deduplicated']);
    }

    public function testForceMovesARecordAcrossMidnightAndRemovesTheNowEmptySourceFile(): void
    {
        // 20:00 UTC + Brisbane's +10:00 offset lands on the next calendar date.
        $this->writeHistoryLines('site-1', '2026-01-01', [
            $this->recordLine('2026-01-01T20:00:00+00:00'),
        ]);

        $command = $this->buildCommand();
        $exit    = $command->run(['--force']);

        self::assertSame(0, $exit);
        self::assertFileDoesNotExist($this->historyFilePath('site-1', '2026-01-01'), 'the now-empty source file must be removed');
        self::assertCount(1, $this->readJsonlLines('site-1', '2026-01-02'));

        $output = $this->output->lastLineDecoded();
        self::assertSame(1, $output['records_moved']);
        self::assertSame(0, $output['records_in_place']);
    }

    public function testRepeatedForceRunsAreIdempotentAndNeverGrowRecordCount(): void
    {
        $this->writeHistoryLines('site-1', '2026-01-01', [
            $this->recordLine('2026-01-01T01:00:00+00:00'),
        ]);

        $command = $this->buildCommand();

        for ($i = 0; $i < 3; $i++) {
            $exit = $command->run(['--force']);
            self::assertSame(0, $exit);
            self::assertCount(
                1,
                $this->readJsonlLines('site-1', '2026-01-01'),
                sprintf('record count must stay at 1 after run #%d, matching the user-reported doubling bug', $i + 1),
            );
        }
    }

    protected function setUp(): void
    {
        $this->dataDirectory = sys_get_temp_dir() . '/uphptime-migrate-' . bin2hex(random_bytes(4));
        mkdir($this->dataDirectory . '/history', 0775, true);
        $this->output = new FakeConsoleOutput();
    }

    protected function tearDown(): void
    {
        if (!is_dir($this->dataDirectory)) {
            return;
        }

        foreach (glob($this->dataDirectory . '/history/*/*') ?: [] as $file) {
            unlink($file);
        }

        foreach (glob($this->dataDirectory . '/history/*') ?: [] as $dir) {
            @rmdir($dir);
        }

        @rmdir($this->dataDirectory . '/history');
        @rmdir($this->dataDirectory);
    }

    // The command only treats a leading "/" as absolute; strip Windows's drive letter and add one (a no-op where temp paths already start with "/").
    private function asCommandPath(string $path): string
    {
        $posix = str_replace('\\', '/', $path);
        if (preg_match('#^[A-Za-z]:#', $posix) === 1) {
            $posix = substr($posix, 2);
        }

        return str_starts_with($posix, '/') ? $posix : '/' . $posix;
    }

    private function buildCommand(): StorageMigrateTimezoneCommand
    {
        $sites = new InMemorySiteRepository();
        $sites->save($this->site('site-1'));

        return new StorageMigrateTimezoneCommand(
            $sites,
            new InMemoryRollupRepository(),
            new AtomicWriter(),
            $this->config(),
            $this->output,
        );
    }

    private function config(): Config
    {
        return new Config(
            dataDirectory: $this->asCommandPath($this->dataDirectory),
            checkIntervalSeconds: 300,
            retentionDays: 90,
            requestTimeoutSeconds: 10,
            runBudgetSeconds: 240,
            staleAfterSeconds: 900,
            httpUserAgent: 'uphptime-test',
            timezone: 'Australia/Brisbane',
        );
    }

    private function historyFilePath(string $siteId, string $date): string
    {
        return $this->dataDirectory . '/history/' . $siteId . '/' . $date . '.jsonl';
    }

    /** @return string[] */
    private function readJsonlLines(string $siteId, string $date): array
    {
        $path = $this->historyFilePath($siteId, $date);
        if (!is_file($path)) {
            return [];
        }

        return array_values(array_filter(
            file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [],
            static fn(string $line): bool => trim($line) !== '',
        ));
    }

    /** @return array<string, mixed> */
    private function recordLine(string $ts): array
    {
        return (new HistoryRecord(
            ts: new DateTimeImmutable($ts),
            siteId: 'site-1',
            checkType: CheckType::Http,
            source: 'self',
            outcome: CheckOutcome::Pass,
            httpStatus: 200,
            responseTimeMs: 10,
            keywordMatched: null,
            error: null,
            stateBefore: SiteState::Unknown,
            stateAfter: SiteState::Up,
            excludedByMaintenance: false,
        ))->toArray();
    }

    private function site(string $id): Site
    {
        $createdAt = new DateTimeImmutable('2026-01-01T00:00:00+00:00');

        return new Site(
            $id,
            $id,
            'https://example.test/',
            null,
            null,
            CheckType::Http,
            null,
            null,
            null,
            true,
            $createdAt,
            new SiteRuntimeState(SiteState::Unknown, 0, null, null, $createdAt, null),
        );
    }

    /** @param array<int, array<string, mixed>> $lines */
    private function writeHistoryLines(string $siteId, string $date, array $lines): void
    {
        $dir = $this->dataDirectory . '/history/' . $siteId;
        if (!is_dir($dir)) {
            mkdir($dir, 0775, true);
        }

        $contents = '';
        foreach ($lines as $line) {
            $contents .= json_encode($line, JSON_THROW_ON_ERROR) . "\n";
        }

        file_put_contents($dir . '/' . $date . '.jsonl', $contents);
    }
}

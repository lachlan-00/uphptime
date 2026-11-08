<?php

declare(strict_types=1);

/**
 * vim:set softtabstop=4 shiftwidth=4 expandtab:
 *
 * LICENSE: GNU Affero General Public License, version 3 (AGPL-3.0-or-later)
 * Copyright uPHPtime contributors, 2026
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

namespace Uphptime\Cli\Commands;

use DateTimeImmutable;
use DateTimeZone;
use Uphptime\Cli\ArgvOptions;
use Uphptime\Cli\CommandInterface;
use Uphptime\Cli\ConsoleOutputInterface;
use Uphptime\Config\Config;
use Uphptime\History\HistoryRecord;
use Uphptime\Storage\RollupRepositoryInterface;
use Uphptime\Storage\SiteRepositoryInterface;
use Uphptime\Support\AtomicWriter;

/**
 * Re-partitions JSONL history files from UTC-dated files to the configured timezone-dated files
 * and deletes rollups so they are rebuilt from the corrected history on the next check:run.
 *
 * For PDO backends, no data migration is needed — the stored timestamps are precise. Only the
 * dayBounds query must use the configured timezone (fixed separately in PdoHistoryRepository).
 */
final class StorageMigrateTimezoneCommand implements CommandInterface
{
    public function __construct(
        private readonly SiteRepositoryInterface $sites,
        private readonly RollupRepositoryInterface $rollups,
        private readonly AtomicWriter $writer,
        private readonly Config $config,
        private readonly ConsoleOutputInterface $output,
    ) {}

    public function run(array $args): int
    {
        $options = ArgvOptions::parse($args);

        // Only the JSON backend needs file re-partitioning; PDO stores precise timestamps.
        if ($this->config->storageBackend !== 'json') {
            $this->output->writeLine(json_encode([
                'message' => 'Storage backend is "' . $this->config->storageBackend . '" — no file re-partitioning needed.',
                'note' => 'PDO stores precise UTC timestamps; dayBounds() is fixed in code to use the configured timezone.',
            ], JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT));

            return 0;
        }

        $targetTimezone = new DateTimeZone($this->config->timezone);

        if ($this->config->timezone === 'UTC') {
            $this->output->writeLine(json_encode([
                'message' => 'Configured timezone is already UTC — no migration needed.',
            ], JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT));

            return 0;
        }

        $dryRun = isset($options['dry-run']);
        $force  = isset($options['force']);

        if (!$force && !$dryRun) {
            $this->output->writeErrorLine(json_encode([
                'error' => 'Pass --force to apply changes, or --dry-run to preview.',
            ], JSON_THROW_ON_ERROR));

            return 1;
        }

        $historyBaseDir = $this->config->dataDirectory;
        if (str_starts_with($historyBaseDir, './') || !str_starts_with($historyBaseDir, '/')) {
            // Resolve relative paths the same way service_definitions does.
            $historyBaseDir = dirname(__DIR__, 4) . '/' . $this->config->dataDirectory;
        }

        $historyDir  = rtrim($historyBaseDir, '/\\') . '/history';
        $rollupsDir  = rtrim($historyBaseDir, '/\\') . '/rollups';

        if (!is_dir($historyDir)) {
            $this->output->writeLine(json_encode([
                'message' => 'No history directory found at ' . $historyDir,
            ], JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT));

            return 0;
        }

        $sites = $this->sites->all();
        if (!$sites) {
            $this->output->writeLine(json_encode([
                'message' => 'No sites configured — nothing to migrate.',
            ], JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT));

            return 0;
        }

        $siteIds = array_map(static fn($s): string => $s->id, $sites);

        $stats = [
            'sites_processed' => 0,
            'files_migrated' => 0,
            'records_moved' => 0,
            'records_in_place' => 0,
            'rollups_deleted' => 0,
            'errors' => [],
        ];

        foreach ($siteIds as $siteId) {
            $siteHistoryDir = $historyDir . '/' . $siteId;
            if (!is_dir($siteHistoryDir)) {
                continue;
            }

            $stats['sites_processed']++;

            // Collect all existing UTC-dated JSONL files for this site.
            $files = [];
            foreach (scandir($siteHistoryDir) ?: [] as $entry) {
                if (preg_match('/^(\d{4}-\d{2}-\d{2})\.jsonl$/', $entry, $matches) === 1) {
                    $files[$matches[1]] = $siteHistoryDir . '/' . $entry;
                }
            }

            if (!$files) {
                continue;
            }

            ksort($files);

            // Read all records from all files, grouped by their original UTC date.
            $allRecords = [];
            foreach ($files as $utcDate => $filePath) {
                $lines = file($filePath, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
                if ($lines === false) {
                    $stats['errors'][] = sprintf('Failed to read %s', $filePath);
                    continue;
                }

                foreach ($lines as $line) {
                    $line = trim($line);
                    if ($line === '') {
                        continue;
                    }

                    $data   = json_decode($line, true, 512, JSON_THROW_ON_ERROR);
                    $record = HistoryRecord::fromArray($data);

                    // Convert the record's UTC timestamp to the target timezone date.
                    $tsInTargetTz = new DateTimeImmutable(
                        $record->ts->format('Y-m-d H:i:s.u'),
                        new DateTimeZone('UTC')
                    );
                    $tsInTargetTz = $tsInTargetTz->setTimezone($targetTimezone);
                    $targetDate   = $tsInTargetTz->format('Y-m-d');

                    $allRecords[$targetDate][] = $record;
                }
            }

            // In dry-run mode, just report what would happen.
            if ($dryRun) {
                $recordsMoved   = 0;
                $recordsInPlace = 0;
                foreach ($allRecords as $targetDate => $records) {
                    foreach ($records as $record) {
                        $originalUtcDate = $record->ts->format('Y-m-d');
                        if ($originalUtcDate === $targetDate) {
                            $recordsInPlace++;
                        } else {
                            $recordsMoved++;
                        }
                    }
                }

                $stats['records_moved'] += $recordsMoved;
                $stats['records_in_place'] += $recordsInPlace;
                $stats['files_migrated'] += count($files);

                continue;
            }

            // Write records to their new timezone-dated files.
            foreach ($allRecords as $targetDate => $records) {
                $newPath = $siteHistoryDir . '/' . $targetDate . '.jsonl';

                // If a file with this target date already exists, append to it.
                $existingRecords = [];
                if (is_file($newPath)) {
                    $existingLines = file($newPath, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
                    if ($existingLines !== false) {
                        foreach ($existingLines as $line) {
                            $line = trim($line);
                            if ($line !== '') {
                                $existingRecords[] = json_decode($line, true, 512, JSON_THROW_ON_ERROR);
                            }
                        }
                    }
                }

                // Combine existing records with new records.
                $allLinesForFile = [];
                foreach ($existingRecords as $existingData) {
                    $allLinesForFile[] = json_encode($existingData, JSON_THROW_ON_ERROR) . "\n";
                }

                foreach ($records as $record) {
                    $allLinesForFile[] = json_encode($record->toArray(), JSON_THROW_ON_ERROR) . "\n";
                    $originalUtcDate   = $record->ts->format('Y-m-d');
                    if ($originalUtcDate === $targetDate) {
                        $stats['records_in_place']++;
                    } else {
                        $stats['records_moved']++;
                    }
                }

                // Write atomically.
                $this->writer->write($newPath, implode('', $allLinesForFile));
            }

            // Delete the old UTC-dated files that are no longer needed.
            foreach ($files as $utcDate => $filePath) {
                $newPath = $siteHistoryDir . '/' . $utcDate . '.jsonl';
                // Only delete if the UTC date file is different from where records ended up.
                // If all records from this UTC date stayed in the same file, keep it.
                $stillNeeded = false;
                foreach ($allRecords as $targetDate => $records) {
                    if ($targetDate === $utcDate) {
                        foreach ($records as $record) {
                            if ($record->ts->format('Y-m-d') === $utcDate) {
                                $stillNeeded = true;
                                break 2;
                            }
                        }
                    }
                }

                if (!$stillNeeded && is_file($filePath)) {
                    unlink($filePath);
                }
            }

            $stats['files_migrated'] += count($files);
        }

        // Delete all rollups so they are rebuilt from corrected history.
        if (!$dryRun && is_dir($rollupsDir)) {
            foreach ($siteIds as $siteId) {
                $dates = $this->rollups->datesWithRollup($siteId);
                foreach ($dates as $date) {
                    $this->rollups->delete($siteId, $date);
                    $stats['rollups_deleted']++;
                }
            }
        } else {
            // Dry-run: count rollups that would be deleted.
            foreach ($siteIds as $siteId) {
                $dates = $this->rollups->datesWithRollup($siteId);
                $stats['rollups_deleted'] += count($dates);
            }
        }

        $this->output->writeLine(json_encode([
            'dry_run' => $dryRun,
            'source_timezone' => 'UTC',
            'target_timezone' => $this->config->timezone,
            'sites_processed' => $stats['sites_processed'],
            'files_migrated' => $stats['files_migrated'],
            'records_moved' => $stats['records_moved'],
            'records_in_place' => $stats['records_in_place'],
            'rollups_deleted' => $stats['rollups_deleted'],
            'next_step' => 'Run "check:run" to rebuild rollups from corrected history.',
        ], JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT));

        if ($stats['errors']) {
            $this->output->writeErrorLine(json_encode([
                'errors' => $stats['errors'],
            ], JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT));

            return 1;
        }

        return 0;
    }
}

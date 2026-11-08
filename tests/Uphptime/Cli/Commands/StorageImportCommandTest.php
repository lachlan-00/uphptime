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

namespace Uphptime\Tests\Cli\Commands;

use DateTimeImmutable;
use FilesystemIterator;
use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use Uphptime\Check\CheckOutcome;
use Uphptime\Cli\Commands\StorageImportCommand;
use Uphptime\Config\Config;
use Uphptime\Site\CheckType;
use Uphptime\Site\Site;
use Uphptime\Site\SiteRuntimeState;
use Uphptime\Site\SiteState;
use Uphptime\Storage\JsonSiteRepository;
use Uphptime\Storage\Portability\StorageCopier;
use Uphptime\Storage\Portability\StorageWiper;
use Uphptime\Support\AtomicWriter;
use Uphptime\Tests\Cli\FakeConsoleOutput;
use Uphptime\Tests\Storage\InMemoryHistoryRepository;
use Uphptime\Tests\Storage\InMemoryPeerRepository;
use Uphptime\Tests\Storage\InMemoryPeerReputationRepository;
use Uphptime\Tests\Storage\InMemoryRollupRepository;
use Uphptime\Tests\Storage\InMemorySiteRepository;

final class StorageImportCommandTest extends TestCase
{
    private string $dir;

    public function testForceWipesTheDestinationFirst(): void
    {
        $this->seedJsonExport('site-a');
        $sites = new InMemorySiteRepository();
        $sites->save($this->site('already-here'));

        $output   = new FakeConsoleOutput();
        $exitCode = $this->command($sites, $output)->run(['--dir=' . $this->dir, '--force']);

        self::assertSame(0, $exitCode);
        self::assertNull($sites->find('already-here'));
        self::assertNotNull($sites->find('site-a'));
    }

    public function testImportsJsonFilesOntoTheConfiguredBackend(): void
    {
        $this->seedJsonExport('site-a');
        $sites  = new InMemorySiteRepository();
        $output = new FakeConsoleOutput();

        $exitCode = $this->command($sites, $output)->run(['--dir=' . $this->dir]);

        self::assertSame(0, $exitCode);
        self::assertSame(1, $output->lastLineDecoded()['sites']);
        self::assertNotNull($sites->find('site-a'));
    }

    public function testRefusesANonEmptyDestinationWithoutForce(): void
    {
        $this->seedJsonExport('site-a');
        $sites = new InMemorySiteRepository();
        $sites->save($this->site('already-here'));

        $output   = new FakeConsoleOutput();
        $exitCode = $this->command($sites, $output)->run(['--dir=' . $this->dir]);

        self::assertSame(1, $exitCode);
        self::assertNotNull($output->lastErrorLine());
        self::assertNull($sites->find('site-a'));
    }

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir() . '/uphptime-import-' . bin2hex(random_bytes(4));
    }

    protected function tearDown(): void
    {
        if (!is_dir($this->dir)) {
            return;
        }

        $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($this->dir, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST);
        foreach ($iterator as $entry) {
            $entry->isDir() ? rmdir((string) $entry) : unlink((string) $entry);
        }

        rmdir($this->dir);
    }

    private function command(InMemorySiteRepository $sites, FakeConsoleOutput $output): StorageImportCommand
    {
        return new StorageImportCommand(
            $sites,
            new InMemoryPeerRepository(),
            new InMemoryPeerReputationRepository(),
            new InMemoryHistoryRepository(),
            new InMemoryRollupRepository(),
            new AtomicWriter(),
            new Config('data', 300, 90, 10, 240, 900, 'uphptime-test', 'UTC'),
            new StorageCopier(),
            new StorageWiper(),
            $output,
        );
    }

    private function seedJsonExport(string $siteId): void
    {
        $repository = new JsonSiteRepository(new AtomicWriter(), $this->dir . '/sites.json');
        $repository->save($this->site($siteId));
    }

    private function site(string $id): Site
    {
        $now = new DateTimeImmutable('2026-01-01T00:00:00+00:00');

        return new Site(
            id: $id,
            name: 'Example',
            url: 'https://example.test/',
            host: null,
            port: null,
            checkType: CheckType::Http,
            keyword: null,
            keywordMode: null,
            checkIntervalSeconds: null,
            enabled: true,
            createdAt: $now,
            state: new SiteRuntimeState(SiteState::Up, 0, CheckOutcome::Pass, $now, $now, $now),
        );
    }
}

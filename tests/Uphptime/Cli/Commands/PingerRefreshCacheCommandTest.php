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
use Uphptime\Cli\Commands\PingerRefreshCacheCommand;
use Uphptime\Config\Config;
use Uphptime\Pinger\Cache\CacheRefresher;
use Uphptime\Pinger\Cache\JsonCheckCacheRepository;
use Uphptime\Pinger\CacheRefreshRunner;
use Uphptime\Scheduling\Lockfile;
use Uphptime\Support\AtomicWriter;
use Uphptime\Tests\Cli\FakeConsoleOutput;
use Uphptime\Tests\Pinger\FakeDelegatedCheckExecutor;
use Uphptime\Tests\Support\FakeClock;

final class PingerRefreshCacheCommandTest extends TestCase
{
    public function testExitsTwoImmediatelyWhenLockIsAlreadyHeld(): void
    {
        $lockPath = sys_get_temp_dir() . '/uphptime-pinger-cache-cmd-lock-' . bin2hex(random_bytes(4)) . '.lock';
        $holder   = new Lockfile($lockPath);
        self::assertTrue($holder->acquire());

        $directory = sys_get_temp_dir() . '/uphptime-pinger-cache-cmd-' . bin2hex(random_bytes(4));
        mkdir($directory, 0775, true);
        $writer = new AtomicWriter();
        $runner = new CacheRefreshRunner(
            new JsonCheckCacheRepository($writer, $directory),
            new CacheRefresher($writer, $directory),
            new FakeDelegatedCheckExecutor([]),
            new FakeClock(),
            new Config('data', 300, 90, 10, 240, 900, 'uphptime-test', 'UTC'),
        );

        $output  = new FakeConsoleOutput();
        $command = new PingerRefreshCacheCommand($runner, new Lockfile($lockPath), $output);
        self::assertSame(2, $command->run([]));
        self::assertNotNull($output->lastErrorLine());

        $holder->release();
        @unlink($lockPath);
        foreach (glob($directory . '/*') ?: [] as $file) {
            @unlink($file);
        }
        @rmdir($directory);
    }
}

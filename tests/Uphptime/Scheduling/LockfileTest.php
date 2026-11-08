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

namespace Uphptime\Tests\Scheduling;

use PHPUnit\Framework\TestCase;
use Uphptime\Scheduling\Lockfile;

final class LockfileTest extends TestCase
{
    private string $path;

    public function testAnotherProcessHoldingTheLockIsDetected(): void
    {
        $script = sys_get_temp_dir() . '/uphptime-lock-holder-' . bin2hex(random_bytes(4)) . '.php';
        file_put_contents($script, '<?php $h = fopen($argv[1], "c"); flock($h, LOCK_EX); fwrite(STDOUT, "locked\n"); sleep(3);');

        $process = proc_open(
            [PHP_BINARY, $script, $this->path],
            [1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $pipes,
        );
        self::assertIsResource($process);

        self::assertSame('locked', trim(fgets($pipes[1])));

        $lockfile = new Lockfile($this->path);
        self::assertFalse($lockfile->acquire(), 'a lock held by another process must be detected immediately');

        fclose($pipes[1]);
        fclose($pipes[2]);
        proc_terminate($process);
        proc_close($process);
        @unlink($script);
    }

    public function testSecondAcquireFailsWhileFirstHoldsIt(): void
    {
        $first  = new Lockfile($this->path);
        $second = new Lockfile($this->path);

        self::assertTrue($first->acquire());
        self::assertFalse($second->acquire(), 'a non-blocking acquire must not block or succeed while the lock is held');

        $first->release();

        self::assertTrue($second->acquire(), 'releasing the first lock must free it for a later acquire');
        $second->release();
    }

    protected function setUp(): void
    {
        $this->path = sys_get_temp_dir() . '/uphptime-lock-' . bin2hex(random_bytes(4)) . '.lock';
    }

    protected function tearDown(): void
    {
        @unlink($this->path);
    }
}

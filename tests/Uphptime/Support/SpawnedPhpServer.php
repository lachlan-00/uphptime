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

namespace Uphptime\Tests\Support;

use RuntimeException;

/**
 * Spawns `php -S 127.0.0.1:<port> -t <docRoot>` as a real OS subprocess and waits for it to accept
 * connections, so integration tests exercise a real HTTP/socket stack rather than a fake transport.
 */
final class SpawnedPhpServer
{
    public readonly int $port;
    private readonly int $pid;

    /** @var resource */
    private $process;

    private bool $stopped = false;

    /** @param array<string, string> $env */
    public function __construct(string $docRoot, ?string $router = null, array $env = [])
    {
        $this->port = random_int(20000, 39000);

        // Array form bypasses the shell entirely (no cmd.exe wrapper on Windows), so the PID proc_open
        // hands back is the real php.exe process and proc_terminate()/taskkill actually reach it.
        $command = [PHP_BINARY, '-S', sprintf('127.0.0.1:%d', $this->port), '-t', $docRoot];
        if ($router !== null) {
            $command[] = $router;
        }

        // Merge onto the full current environment (never replace it): a Windows child process that
        // loses SYSTEMROOT/PHPRC etc. can silently load a different php.ini or fail to load extensions.
        $fullEnv = array_merge((array) getenv(), $env);

        $process = proc_open($command, [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, $docRoot, $fullEnv);
        if ($process === false) {
            throw new RuntimeException('Unable to spawn PHP built-in server.');
        }

        $status = proc_get_status($process);
        if ($status === false) {
            throw new RuntimeException('Unable to read spawned PHP server status.');
        }

        $this->process = $process;
        $this->pid     = $status['pid'];
        $this->waitUntilReady();
    }

    public function baseUrl(): string
    {
        return sprintf('http://127.0.0.1:%d', $this->port);
    }

    public function stop(): void
    {
        if ($this->stopped) {
            return;
        }

        $this->stopped = true;

        if (str_starts_with(PHP_OS_FAMILY, 'Windows')) {
            // taskkill /T also reaps any grandchild the php.exe itself spawned.
            @shell_exec(sprintf('taskkill /F /T /PID %d >NUL 2>&1', $this->pid));
        } else {
            proc_terminate($this->process);
        }

        proc_close($this->process);
    }

    private function waitUntilReady(): void
    {
        $deadline = microtime(true) + 5.0;

        while (microtime(true) < $deadline) {
            $socket = @fsockopen('127.0.0.1', $this->port, $errorNumber, $errorMessage, 0.2);
            if ($socket !== false) {
                fclose($socket);

                return;
            }

            usleep(50_000);
        }

        throw new RuntimeException(sprintf('PHP built-in server on port %d never became ready.', $this->port));
    }
}

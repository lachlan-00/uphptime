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

namespace Uphptime\Check\Icmp;

use RuntimeException;

/**
 * Shells out to the platform's `ping` (Linux/macOS iputils-style, or Windows' ping.exe) as an argv
 * array, never a shell string, so a hostile hostname can't inject a second command.
 */
final class SystemPingClient implements IcmpClientInterface
{
    public function ping(string $host, float $timeoutSeconds): int
    {
        [$exitCode, $output] = $this->run($host, $timeoutSeconds);

        if ($exitCode !== 0) {
            throw new RuntimeException(sprintf('Host did not respond to ping: %s', $host));
        }

        // Windows reports a sub-millisecond reply as "time<1ms" rather than "time=0ms".
        if (preg_match('/time[=<]([\d.]+)\s*ms/i', $output, $matches) !== 1) {
            throw new RuntimeException(sprintf('Unable to parse ping output for: %s', $host));
        }

        return (int) round((float) $matches[1]);
    }

    /** @return list<string> */
    private function command(string $host, float $timeoutSeconds): array
    {
        // Windows' -w is a total wait in milliseconds; iputils' -W is a per-reply timeout in seconds.
        if (PHP_OS_FAMILY === 'Windows') {
            $waitMilliseconds = max(1, (int) ceil($timeoutSeconds * 1000));

            return ['ping', '-n', '1', '-w', (string) $waitMilliseconds, $host];
        }

        $waitSeconds = max(1, (int) ceil($timeoutSeconds));

        return ['ping', '-c', '1', '-W', (string) $waitSeconds, $host];
    }

    /** @return array{0: int, 1: string} */
    private function run(string $host, float $timeoutSeconds): array
    {
        $descriptors = [1 => ['pipe', 'w'], 2 => ['pipe', 'w']];

        $process = proc_open($this->command($host, $timeoutSeconds), $descriptors, $pipes);
        if ($process === false) {
            throw new RuntimeException('Unable to start the ping process.');
        }

        $stdout = stream_get_contents($pipes[1]) ?: '';
        fclose($pipes[1]);
        fclose($pipes[2]);
        $exitCode = proc_close($process);

        return [$exitCode, $stdout];
    }
}

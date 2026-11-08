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

namespace Uphptime\Tests\Cli;

use Uphptime\Cli\ConsoleOutputInterface;

/**
 * Captures written lines in memory instead of touching real STDOUT/STDERR streams.
 */
final class FakeConsoleOutput implements ConsoleOutputInterface
{
    /** @var string[] */
    private array $errorLines = [];

    /** @var string[] */
    private array $lines = [];

    public function lastErrorLine(): ?string
    {
        return $this->errorLines === [] ? null : $this->errorLines[count($this->errorLines) - 1];
    }

    public function lastLine(): ?string
    {
        return $this->lines === [] ? null : $this->lines[count($this->lines) - 1];
    }

    public function lastLineDecoded(): mixed
    {
        $line = $this->lastLine();

        return $line === null ? null : json_decode($line, true);
    }

    public function writeErrorLine(string $line): void
    {
        $this->errorLines[] = $line;
    }

    public function writeLine(string $line): void
    {
        $this->lines[] = $line;
    }
}

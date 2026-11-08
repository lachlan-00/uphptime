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

namespace Uphptime\Tests\Cli;

use Uphptime\Cli\CommandInterface;

/**
 * Records the args it was called with and returns a fixed exit code, for testing CliApplication's own
 * dispatch logic without exercising any real command's behavior.
 */
final class FakeCommand implements CommandInterface
{
    /** @var string[] */
    public array $receivedArgs = [];

    public function __construct(private readonly int $exitCode) {}

    public function run(array $args): int
    {
        $this->receivedArgs = $args;

        return $this->exitCode;
    }
}

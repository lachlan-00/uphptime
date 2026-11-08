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

use PHPUnit\Framework\TestCase;
use Uphptime\Cli\CliApplication;

final class CliApplicationTest extends TestCase
{
    /** @return array<string, array{0: string[]}> */
    public static function helpInvocationProvider(): array
    {
        return [
            'no arguments at all' => [['bin/uphptime']],
            'help' => [['bin/uphptime', 'help']],
            '--help' => [['bin/uphptime', '--help']],
            '-h' => [['bin/uphptime', '-h']],
        ];
    }

    /** @dataProvider helpInvocationProvider */
    public function testEveryHelpInvocationPrintsTheFullActionListAndExitsZero(array $argv): void
    {
        $output = new FakeConsoleOutput();
        $app    = new CliApplication(['sites:add' => new FakeCommand(0)], $output);

        $exitCode = $app->run($argv);

        self::assertSame(0, $exitCode);
        self::assertStringContainsString('sites:add', (string) $output->lastLine());
        self::assertNull($output->lastErrorLine());
    }

    public function testKnownActionDispatchesToItsCommandWithTheRemainingArguments(): void
    {
        $command = new FakeCommand(0);
        $app     = new CliApplication(['sites:add' => $command], new FakeConsoleOutput());

        $exitCode = $app->run(['bin/uphptime', 'sites:add', '--url=https://example.test/']);

        self::assertSame(0, $exitCode);
        self::assertSame(['--url=https://example.test/'], $command->receivedArgs);
    }

    public function testUnknownActionIsAJsonErrorMentioningHelp(): void
    {
        $output = new FakeConsoleOutput();
        $app    = new CliApplication(['sites:add' => new FakeCommand(0)], $output);

        $exitCode = $app->run(['bin/uphptime', 'sites:ad']);

        self::assertSame(1, $exitCode);
        $decoded = json_decode((string) $output->lastErrorLine(), true);
        self::assertStringContainsString('sites:ad', (string) $decoded['error']);
        self::assertStringContainsString('help', (string) $decoded['error']);
    }
}

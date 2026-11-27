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
use Uphptime\Cli\Commands\ConfigCheckCommand;
use Uphptime\Config\ConfigKey;
use Uphptime\Support\AtomicWriter;
use Uphptime\Tests\Cli\FakeConsoleOutput;

final class ConfigCheckCommandTest extends TestCase
{
    private string $configPath;
    private FakeConsoleOutput $output;

    public function testCompleteConfigReportsOk(): void
    {
        $complete = [];
        foreach (ConfigKey::cases() as $key) {
            $complete[$key->value] = $key->default();
        }

        file_put_contents($this->configPath, json_encode($complete, JSON_THROW_ON_ERROR));

        $exit = $this->command()->run([]);

        self::assertSame(0, $exit);
        self::assertSame(['path' => $this->configPath, 'status' => 'ok', 'missing' => []], $this->output->lastLineDecoded());
    }

    public function testFixAddsMissingKeysWithoutTouchingExistingOnes(): void
    {
        file_put_contents($this->configPath, json_encode(['timezone' => 'Australia/Sydney', 'check_interval_seconds' => 60], JSON_THROW_ON_ERROR));

        $exit = $this->command()->run(['--fix']);

        self::assertSame(0, $exit);
        $body = $this->output->lastLineDecoded();
        self::assertSame('fixed', $body['status']);
        self::assertArrayNotHasKey('timezone', $body['added']);
        self::assertArrayNotHasKey('check_interval_seconds', $body['added']);
        self::assertSame(90, $body['added']['retention_days']);

        $onDisk = json_decode((string) file_get_contents($this->configPath), true);
        self::assertSame('Australia/Sydney', $onDisk['timezone'], 'an explicitly-set value is never overwritten by --fix');
        self::assertSame(60, $onDisk['check_interval_seconds'], 'a non-default explicit value is preserved');
        self::assertSame(90, $onDisk['retention_days'], 'the missing key is filled in with its default');

        // Running again afterwards must now report ok.
        $secondExit = $this->command()->run([]);
        self::assertSame(0, $secondExit);
        self::assertSame('ok', $this->output->lastLineDecoded()['status']);
    }

    public function testFixOnAMissingFileCreatesItWithEveryDefault(): void
    {
        unlink($this->configPath);

        $exit = $this->command()->run(['--fix']);

        self::assertSame(0, $exit);
        self::assertFileExists($this->configPath);
        $onDisk = json_decode((string) file_get_contents($this->configPath), true);
        self::assertSame(300, $onDisk['check_interval_seconds']);
        self::assertCount(count(ConfigKey::cases()), $onDisk);
    }

    public function testInvalidJsonIsReportedAsAnErrorRatherThanTreatedAsMissingKeys(): void
    {
        file_put_contents($this->configPath, 'not json');

        $exit = $this->command()->run([]);

        self::assertSame(1, $exit);
        self::assertNotNull($this->output->lastErrorLine());
    }

    public function testMissingFileIsTreatedAsEveryKeyMissing(): void
    {
        unlink($this->configPath);

        $exit = $this->command()->run([]);

        self::assertSame(1, $exit);
        $body = $this->output->lastLineDecoded();
        self::assertSame('missing_keys', $body['status']);
        self::assertCount(count(ConfigKey::cases()), $body['missing']);
    }

    public function testMissingKeysWithoutFixReportsThemAndLeavesFileUntouched(): void
    {
        file_put_contents($this->configPath, json_encode(['timezone' => 'Australia/Sydney'], JSON_THROW_ON_ERROR));

        $exit = $this->command()->run([]);

        self::assertSame(1, $exit, 'missing keys without --fix is a non-zero, actionable result');
        $body = $this->output->lastLineDecoded();
        self::assertSame('missing_keys', $body['status']);
        self::assertSame(300, $body['missing']['check_interval_seconds']);
        self::assertArrayNotHasKey('timezone', $body['missing'], 'a key already present is never reported as missing');

        $onDisk = json_decode((string) file_get_contents($this->configPath), true);
        self::assertSame(['timezone' => 'Australia/Sydney'], $onDisk, '--fix was not passed, so the file must be untouched');
    }

    protected function setUp(): void
    {
        $this->configPath  = tempnam(sys_get_temp_dir(), 'uphptime-config-check-');
        $this->output      = new FakeConsoleOutput();
    }

    protected function tearDown(): void
    {
        @unlink($this->configPath);
        @unlink($this->configPath . '.lock');
    }

    private function command(): ConfigCheckCommand
    {
        return new ConfigCheckCommand($this->configPath, new AtomicWriter(), $this->output);
    }
}

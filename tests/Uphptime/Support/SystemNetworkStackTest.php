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

namespace Uphptime\Tests\Support;

use PHPUnit\Framework\TestCase;
use Uphptime\Support\SystemNetworkStack;

final class SystemNetworkStackTest extends TestCase
{
    private string $fixturePath;

    public function testEmptyFileIsFalse(): void
    {
        file_put_contents($this->fixturePath, '');

        self::assertFalse((new SystemNetworkStack($this->fixturePath))->hasIpv6Route());
    }

    public function testGlobalScopeAddressOnARealInterfaceIsTrue(): void
    {
        file_put_contents($this->fixturePath, <<<'FIXTURE'
            fe80000000000000444245fffe57e7c7 0d 40 20 80 veth0
            24063400040c65b0b62e99fffee6ec5a 02 80 00 00 eno1
            00000000000000000000000000000001 01 80 10 80       lo
            FIXTURE);

        self::assertTrue((new SystemNetworkStack($this->fixturePath))->hasIpv6Route());
    }

    public function testLinkLocalAndLoopbackOnlyIsFalse(): void
    {
        file_put_contents($this->fixturePath, <<<'FIXTURE'
            fe80000000000000444245fffe57e7c7 0d 40 20 80 veth0
            00000000000000000000000000000001 01 80 10 80       lo
            FIXTURE);

        self::assertFalse((new SystemNetworkStack($this->fixturePath))->hasIpv6Route());
    }

    public function testMissingFileIsFalseNotAnError(): void
    {
        self::assertFalse((new SystemNetworkStack($this->fixturePath))->hasIpv6Route());
    }

    // The real /proc/net/if_inet6 on whatever machine runs this suite - proves the parser doesn't
    // choke on it either way, without asserting a specific answer (this machine's own IPv6 state
    // isn't something the test suite controls or should depend on).
    public function testRealProcFileParsesWithoutError(): void
    {
        $stack = new SystemNetworkStack();

        self::assertIsBool($stack->hasIpv6Route());
    }

    protected function setUp(): void
    {
        $this->fixturePath = sys_get_temp_dir() . '/uphptime-if-inet6-' . bin2hex(random_bytes(4));
    }

    protected function tearDown(): void
    {
        @unlink($this->fixturePath);
    }
}

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

use PHPUnit\Framework\TestCase;
use Uphptime\Support\PathResolver;

final class PathResolverTest extends TestCase
{
    public function testRelativePathIsJoinedToTheRoot(): void
    {
        self::assertSame('/repo' . DIRECTORY_SEPARATOR . 'data', PathResolver::resolve('/repo', 'data'));
    }

    public function testUnixAbsolutePathIsReturnedAsIs(): void
    {
        self::assertSame('/var/uphptime/data', PathResolver::resolve('/repo', '/var/uphptime/data'));
    }

    public function testWindowsDriveLetterPathIsReturnedAsIs(): void
    {
        self::assertSame('C:\\uphptime\\data', PathResolver::resolve('C:\\repo', 'C:\\uphptime\\data'));
    }

    public function testWindowsUncPathIsReturnedAsIs(): void
    {
        self::assertSame('\\\\server\\share\\data', PathResolver::resolve('C:\\repo', '\\\\server\\share\\data'));
    }
}

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
use Uphptime\Support\AtomicWriter;

final class AtomicWriterTest extends TestCase
{
    private string $directory;

    public function testOverwritingAnExistingDestinationWorks(): void
    {
        $writer = new AtomicWriter();
        $path   = $this->directory . '/data.json';

        $writer->write($path, 'first');
        $writer->write($path, 'second');

        self::assertSame('second', $writer->read($path));
    }

    public function testReadModifyWriteCallbackReturningNullOnAMissingFileLeavesNoFileBehind(): void
    {
        $writer = new AtomicWriter();
        $path   = $this->directory . '/never-created.json';

        $writer->readModifyWrite($path, static fn(?string $current): ?string => null);

        self::assertNull($writer->read($path));
    }

    public function testReadModifyWriteSkipsTheWriteWhenTheCallbackReturnsNull(): void
    {
        $writer = new AtomicWriter();
        $path   = $this->directory . '/data.json';

        $writer->write($path, 'original');
        $writer->readModifyWrite($path, static fn(?string $current): ?string => null);

        self::assertSame('original', $writer->read($path));
    }

    public function testReadOfMissingFileReturnsNull(): void
    {
        $writer = new AtomicWriter();

        self::assertNull($writer->read($this->directory . '/missing.json'));
    }

    public function testWriteLeavesNoStrayTempFilesBehind(): void
    {
        $writer = new AtomicWriter();
        $path   = $this->directory . '/data.json';

        $writer->write($path, 'contents');

        $entries = array_values(array_diff(scandir($this->directory) ?: [], ['.', '..']));
        sort($entries);

        self::assertSame(['data.json', 'data.json.lock'], $entries);
    }

    public function testWriteThenReadRoundTrips(): void
    {
        $writer = new AtomicWriter();
        $path   = $this->directory . '/data.json';

        $writer->write($path, '{"a":1}');

        self::assertSame('{"a":1}', $writer->read($path));
    }

    protected function setUp(): void
    {
        $this->directory = sys_get_temp_dir() . '/uphptime-atomic-' . bin2hex(random_bytes(4));
        mkdir($this->directory, 0775, true);
    }

    protected function tearDown(): void
    {
        foreach (glob($this->directory . '/*') ?: [] as $file) {
            unlink($file);
        }
        rmdir($this->directory);
    }
}

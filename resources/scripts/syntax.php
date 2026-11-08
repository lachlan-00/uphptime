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

// Portable `php -l` sweep over src/, tests/ and bin/ (no shell find/xargs, so it also runs on Windows).
$root = dirname(__DIR__, 2);
$dirs = ['src', 'tests', 'bin'];
$failures = 0;
$checked = 0;

foreach ($dirs as $dir) {
    $path = $root . DIRECTORY_SEPARATOR . $dir;
    if (!is_dir($path)) {
        continue;
    }

    $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($path, FilesystemIterator::SKIP_DOTS));
    foreach ($iterator as $file) {
        if ($file->isDir()) {
            continue;
        }
        if ($file->getExtension() !== 'php' && $file->getFilename() !== 'uphptime') {
            continue;
        }

        $checked++;
        $output = [];
        $exitCode = 0;
        exec(sprintf('php -l %s 2>&1', escapeshellarg($file->getPathname())), $output, $exitCode);
        if ($exitCode !== 0) {
            $failures++;
            fwrite(STDERR, implode("\n", $output) . "\n");
        }
    }
}

fwrite(STDOUT, sprintf("Checked %d files, %d failures.\n", $checked, $failures));
exit($failures > 0 ? 1 : 0);

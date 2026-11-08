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

// Regenerates docs/openapi.json from Uphptime\Api\OpenApi\OpenApiSpecBuilder, the single source of
// truth for uphptime's REST surface. Pass --check to verify the committed file is current without
// writing (used by "composer api:docs:check", part of the "qa" gate).
require dirname(__DIR__, 2) . '/vendor/autoload.php';

use Uphptime\Api\OpenApi\OpenApiSpecBuilder;

$root   = dirname(__DIR__, 2);
$target = $root . '/docs/openapi.json';
$check  = in_array('--check', $argv, true);

$spec     = (new OpenApiSpecBuilder())->build();
$rendered = json_encode($spec, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n";

if ($check) {
    $current = is_file($target) ? file_get_contents($target) : null;
    if ($current === $rendered) {
        fwrite(STDOUT, "docs/openapi.json is up to date.\n");
        exit(0);
    }

    fwrite(STDERR, "docs/openapi.json is stale. Run \"composer api:docs\" and commit the result.\n");
    exit(1);
}

if (!is_dir(dirname($target))) {
    mkdir(dirname($target), 0775, true);
}

file_put_contents($target, $rendered);
fwrite(STDOUT, "Wrote docs/openapi.json.\n");

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

namespace Uphptime\Cli\Commands;

use Uphptime\Cli\ArgvOptions;
use Uphptime\Cli\CommandInterface;
use Uphptime\Cli\ConsoleOutputInterface;
use Uphptime\Config\ConfigKey;
use Uphptime\Support\AtomicWriter;

/**
 * Reports any config/node.json key that's absent (and so silently falling back to its
 * ConfigKey default); --fix rewrites the file with those keys added, touching nothing else.
 */
final class ConfigCheckCommand implements CommandInterface
{
    public function __construct(
        private readonly string $configPath,
        private readonly AtomicWriter $writer,
        private readonly ConsoleOutputInterface $output,
    ) {}

    public function run(array $args): int
    {
        $options = ArgvOptions::parse($args);
        $fix     = isset($options['fix']);

        $decoded = $this->readConfig();
        if ($decoded === null) {
            $this->output->writeErrorLine(json_encode(
                ['error' => sprintf('Config file is not a valid JSON object: %s', $this->configPath)],
                JSON_THROW_ON_ERROR,
            ));

            return 1;
        }

        $missing = [];
        foreach (ConfigKey::cases() as $key) {
            if (!array_key_exists($key->value, $decoded)) {
                $missing[$key->value] = $key->default();
            }
        }

        if ($missing === []) {
            $this->output->writeLine(json_encode(
                ['path' => $this->configPath, 'status' => 'ok', 'missing' => []],
                JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT,
            ));

            return 0;
        }

        if (!$fix) {
            $this->output->writeLine(json_encode([
                'path' => $this->configPath,
                'status' => 'missing_keys',
                'missing' => $missing,
                'next_step' => 'Run with --fix to add these keys with their defaults.',
            ], JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT));

            return 1;
        }

        $this->writer->readModifyWrite($this->configPath, function (?string $current) use ($missing): string {
            $currentDecoded = $current === null ? [] : json_decode($current, true);
            if (!is_array($currentDecoded)) {
                $currentDecoded = [];
            }

            // Existing keys always win; this only ever fills in what's absent.
            return json_encode($currentDecoded + $missing, JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT) . "\n";
        });

        $this->output->writeLine(json_encode(
            ['path' => $this->configPath, 'status' => 'fixed', 'added' => $missing],
            JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT,
        ));

        return 0;
    }

    // A missing file means every key is "missing" (the behavior ConfigLoader already gives it);
    // decodes to [] rather than null so that case is reported, not mistaken for invalid JSON.
    /** @return array<string, mixed>|null */
    private function readConfig(): ?array
    {
        $raw = $this->writer->read($this->configPath);
        if ($raw === null) {
            return [];
        }

        $decoded = json_decode($raw, true);

        return is_array($decoded) ? $decoded : null;
    }
}

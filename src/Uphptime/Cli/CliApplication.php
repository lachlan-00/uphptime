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

namespace Uphptime\Cli;

use Throwable;

/**
 * A fixed action-name to command dispatcher, mirroring Ampache's Method/ApiHandler pattern.
 */
final class CliApplication
{
    private const GROUP_LABELS = [
        'sites' => 'Sites',
        'maintenance' => 'Maintenance windows',
        'check' => 'Checks',
        'report' => 'Reporting',
        'pinger' => 'Pinger cache',
        'peers' => 'Peers',
        'storage' => 'Storage',
    ];

    private const HELP_ACTIONS = ['', 'help', '--help', '-h'];

    /** @param array<string, CommandInterface> $commands */
    public function __construct(
        private readonly array $commands,
        private readonly ConsoleOutputInterface $output,
    ) {}

    /** @param string[] $argv */
    public function run(array $argv): int
    {
        $action = $argv[1] ?? '';
        $args   = array_slice($argv, 2);

        if (in_array($action, self::HELP_ACTIONS, true)) {
            $this->output->writeLine($this->renderHelp());

            return 0;
        }

        if (!isset($this->commands[$action])) {
            $this->output->writeErrorLine(json_encode(
                ['error' => sprintf('Unknown action "%s". Run "php bin/uphptime help" for a list of actions.', $action)],
                JSON_THROW_ON_ERROR,
            ));

            return 1;
        }

        try {
            return $this->commands[$action]->run($args);
        } catch (Throwable $exception) {
            $this->output->writeErrorLine(json_encode(['error' => $exception->getMessage()], JSON_THROW_ON_ERROR));

            return 1;
        }
    }

    // Grouped by the action name's prefix before its first ":", in the order commands were
    // registered - every other command's output is JSON; this is the one deliberate exception,
    // since it exists for a human reading a terminal, not a script parsing stdout.
    private function renderHelp(): string
    {
        $groups = [];
        foreach (array_keys($this->commands) as $action) {
            $groups[explode(':', $action, 2)[0]][] = $action;
        }

        $descriptions  = CommandDescriptions::all();
        $lines         = ['uPHPtime CLI', '', 'Usage: php bin/uphptime <action> [--option=value ...]', ''];

        foreach ($groups as $group => $actions) {
            $lines[] = (self::GROUP_LABELS[$group] ?? ucfirst($group)) . ':';
            foreach ($actions as $action) {
                $lines[] = sprintf('  %-24s %s', $action, $descriptions[$action] ?? '');
            }
            $lines[] = '';
        }

        $lines[] = 'All commands print JSON on stdout and JSON errors on stderr; this help text is the';
        $lines[] = 'one exception. See README.md for full usage examples.';

        return implode("\n", $lines);
    }
}

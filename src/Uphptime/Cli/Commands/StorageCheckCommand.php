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

use Uphptime\Cli\CommandInterface;
use Uphptime\Cli\ConsoleOutputInterface;
use Uphptime\Config\Config;
use Uphptime\Storage\Pdo\PdoConnectionFactory;
use Uphptime\Storage\Pdo\SchemaMigrator;

/**
 * Read-only: reports whether the SQL database is at the required schema version and has every table, column and index
 * schema.sql defines. Exits 1 when it does not. A no-op when storage_backend is "json".
 */
final class StorageCheckCommand implements CommandInterface
{
    public function __construct(
        private readonly Config $config,
        private readonly PdoConnectionFactory $connectionFactory,
        private readonly SchemaMigrator $schemaMigrator,
        private readonly ConsoleOutputInterface $output,
    ) {}

    public function run(array $args): int
    {
        if ($this->config->storageBackend === 'json') {
            $this->output->writeLine(json_encode([
                'status' => 'skipped',
                'reason' => 'storage_backend is "json"; nothing to check.',
            ], JSON_THROW_ON_ERROR));

            return 0;
        }

        $pdo       = $this->connectionFactory->create($this->config);
        $installed = $this->schemaMigrator->installedVersion($pdo);
        $problems  = $this->schemaMigrator->problems($pdo);
        $valid     = $installed === SchemaMigrator::CURRENT_VERSION
            && $problems['missing_tables'] === []
            && $problems['missing_columns'] === []
            && $problems['missing_indexes'] === [];

        $report = [
            'status' => $valid ? 'ok' : 'invalid',
            'backend' => $this->config->storageBackend,
            'schema_version' => $installed,
            'required_version' => SchemaMigrator::CURRENT_VERSION,
            ...$problems,
        ];

        if (!$valid) {
            $report['next_step'] = $installed > SchemaMigrator::CURRENT_VERSION
                ? 'The database is newer than this application; update the application.'
                : 'Run "bin/uphptime storage:upgrade" to install what is missing.';
        }

        $this->output->writeLine(json_encode($report, JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT));

        return $valid ? 0 : 1;
    }
}

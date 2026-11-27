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
 * Brings the SQL schema up to the version this release requires and installs any missing tables, columns and indexes; every other command refuses to run until it has been.
 * A failed step leaves the database at the last completed version and exits 1. A no-op when storage_backend is "json".
 */
final class StorageUpgradeCommand implements CommandInterface
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
                'reason' => 'storage_backend is "json"; nothing to upgrade.',
            ], JSON_THROW_ON_ERROR));

            return 0;
        }

        $versions = $this->schemaMigrator->upgrade($this->connectionFactory->create($this->config));

        $this->output->writeLine(json_encode([
            'status' => $versions['from'] === $versions['to'] && $versions['added'] === [] ? 'current' : 'upgraded',
            'backend' => $this->config->storageBackend,
            'from' => $versions['from'],
            'to' => $versions['to'],
            'added' => $versions['added'],
        ], JSON_THROW_ON_ERROR));

        return 0;
    }
}

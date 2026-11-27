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

namespace Uphptime\Storage\Pdo;

use Uphptime\Config\Config;

/**
 * Refuses to proceed against a SQL database that is not at the schema version this release requires. A no-op for JSON storage.
 */
final class SchemaGuard
{
    public function __construct(
        private readonly Config $config,
        private readonly PdoConnectionFactory $connectionFactory,
        private readonly SchemaMigrator $schemaMigrator,
    ) {}

    public function assertCurrent(): void
    {
        if ($this->config->storageBackend === 'json') {
            return;
        }

        $this->schemaMigrator->assertCurrent($this->connectionFactory->create($this->config));
    }
}

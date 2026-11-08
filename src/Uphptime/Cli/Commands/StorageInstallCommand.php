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

use PDO;
use RuntimeException;
use Uphptime\Cli\CommandInterface;
use Uphptime\Cli\ConsoleOutputInterface;
use Uphptime\Config\Config;
use Uphptime\Storage\Pdo\PdoConnectionFactory;

/**
 * Creates the configured SQL backend's tables, idempotently. A no-op (never connects) when
 * storage_backend is "json", so a JSON-only install never needs a PDO driver loaded at all.
 */
final class StorageInstallCommand implements CommandInterface
{
    public function __construct(
        private readonly Config $config,
        private readonly PdoConnectionFactory $connectionFactory,
        private readonly ConsoleOutputInterface $output,
    ) {}

    public function run(array $args): int
    {
        if ($this->config->storageBackend === 'json') {
            $this->output->writeLine(json_encode([
                'status' => 'skipped',
                'reason' => 'storage_backend is "json"; nothing to install.',
            ], JSON_THROW_ON_ERROR));

            return 0;
        }

        $path     = $this->schemaPath();
        $contents = file_get_contents($path);
        if ($contents === false) {
            $this->output->writeErrorLine(json_encode(['error' => sprintf('Unable to read schema file: %s', $path)], JSON_THROW_ON_ERROR));

            return 1;
        }

        $pdo = $this->connectionFactory->create($this->config);
        foreach ($this->statements($contents) as $statement) {
            $pdo->exec($statement);
        }

        $this->upgradeHistoryPeerFields($pdo);

        $this->output->writeLine(json_encode([
            'status' => 'installed',
            'backend' => $this->config->storageBackend,
        ], JSON_THROW_ON_ERROR));

        return 0;
    }

    private function schemaPath(): string
    {
        $dialect = $this->config->storageBackend === 'pgsql' ? 'pgsql' : 'mysql';
        $path    = dirname(__DIR__, 4) . '/resources/sql/' . $dialect . '/schema.sql';

        if (!is_file($path)) {
            throw new RuntimeException(sprintf('Schema file not found: %s', $path));
        }

        return $path;
    }

    /** @return string[] */
    private function statements(string $contents): array
    {
        $statements = array_map('trim', explode(";\n", $contents));

        return array_values(array_filter($statements, static fn(string $statement): bool => $statement !== ''));
    }

    private function upgradeHistoryPeerFields(PDO $pdo): void
    {
        $schema    = $this->config->storageBackend === 'pgsql' ? 'current_schema()' : 'DATABASE()';
        $statement = $pdo->query("SELECT column_name FROM information_schema.columns WHERE table_schema = $schema AND table_name = 'history'");
        if ($statement === false) {
            throw new RuntimeException('Unable to inspect history table columns.');
        }

        $columns = $statement->fetchAll(PDO::FETCH_COLUMN);
        foreach (['peer_id' => 'VARCHAR(64)', 'peer_label' => 'TEXT'] as $name => $type) {
            if (!in_array($name, $columns, true)) {
                $pdo->exec("ALTER TABLE history ADD COLUMN $name $type NULL");
            }
        }
    }
}

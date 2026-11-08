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

namespace Uphptime\Cli\Commands;

use Uphptime\Cli\ArgvOptions;
use Uphptime\Cli\CommandInterface;
use Uphptime\Cli\ConsoleOutputInterface;
use Uphptime\Config\Config;
use Uphptime\Storage\HistoryRepositoryInterface;
use Uphptime\Storage\JsonHistoryRepository;
use Uphptime\Storage\JsonPeerRepository;
use Uphptime\Storage\JsonPeerReputationRepository;
use Uphptime\Storage\JsonRollupRepository;
use Uphptime\Storage\JsonSiteRepository;
use Uphptime\Storage\PeerRepositoryInterface;
use Uphptime\Storage\PeerReputationRepositoryInterface;
use Uphptime\Storage\Portability\StorageCopier;
use Uphptime\Storage\Portability\StorageRepositorySet;
use Uphptime\Storage\Portability\StorageWiper;
use Uphptime\Storage\RollupRepositoryInterface;
use Uphptime\Storage\SiteRepositoryInterface;
use Uphptime\Support\AtomicWriter;

/**
 * Copies a JSON export (storage:export's own output, or any storage_backend: "json" data_dir) onto this
 * node's currently configured backend.
 */
final class StorageImportCommand implements CommandInterface
{
    public function __construct(
        private readonly SiteRepositoryInterface $sites,
        private readonly PeerRepositoryInterface $peers,
        private readonly PeerReputationRepositoryInterface $peerReputations,
        private readonly HistoryRepositoryInterface $history,
        private readonly RollupRepositoryInterface $rollups,
        private readonly AtomicWriter $writer,
        private readonly Config $config,
        private readonly StorageCopier $copier,
        private readonly StorageWiper $wiper,
        private readonly ConsoleOutputInterface $output,
    ) {}

    public function run(array $args): int
    {
        $options = ArgvOptions::parse($args);
        $dir     = isset($options['dir']) ? rtrim($options['dir'], '/') : null;

        if ($dir === null) {
            $this->output->writeErrorLine(json_encode(['error' => 'Missing required --dir option.'], JSON_THROW_ON_ERROR));

            return 1;
        }

        $destination = new StorageRepositorySet($this->sites, $this->peers, $this->peerReputations, $this->history, $this->rollups);

        if (!isset($options['force']) && !$destination->isEmpty()) {
            $this->output->writeErrorLine(json_encode([
                'error' => 'The configured storage backend already holds data; pass --force to overwrite it.',
            ], JSON_THROW_ON_ERROR));

            return 1;
        }

        if (isset($options['force'])) {
            $this->wiper->wipe($destination);
        }

        $source  = $this->jsonRepositorySet($dir);
        $summary = $this->copier->copy($source, $destination);

        $this->output->writeLine(json_encode([
            'dir' => $dir,
            'sites' => $summary->sites,
            'peers' => $summary->peers,
            'peer_reputations' => $summary->peerReputations,
            'history_records' => $summary->historyRecords,
            'rollups' => $summary->rollups,
        ], JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT));

        return 0;
    }

    private function jsonRepositorySet(string $dir): StorageRepositorySet
    {
        return new StorageRepositorySet(
            new JsonSiteRepository($this->writer, $dir . '/sites.json'),
            new JsonPeerRepository($this->writer, $dir . '/peers.json'),
            new JsonPeerReputationRepository($this->writer, $dir . '/peer_reputation.json', $this->config->peerReputationNeutralScore),
            new JsonHistoryRepository($dir . '/history'),
            new JsonRollupRepository($this->writer, $dir . '/rollups'),
        );
    }
}

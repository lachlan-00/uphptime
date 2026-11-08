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

use function DI\autowire;
use function DI\factory;

use Psr\Container\ContainerInterface;
use Uphptime\Config\Config;
use Uphptime\Storage\HistoryRepositoryInterface;
use Uphptime\Storage\JsonHistoryRepository;
use Uphptime\Storage\JsonPeerRepository;
use Uphptime\Storage\JsonPeerReputationRepository;
use Uphptime\Storage\JsonRollupRepository;
use Uphptime\Storage\JsonSiteRepository;
use Uphptime\Storage\Pdo\MySqlDialect;
use Uphptime\Storage\Pdo\PdoConnectionFactory;
use Uphptime\Storage\Pdo\PdoHistoryRepository;
use Uphptime\Storage\Pdo\PdoPeerRepository;
use Uphptime\Storage\Pdo\PdoPeerReputationRepository;
use Uphptime\Storage\Pdo\PdoRollupRepository;
use Uphptime\Storage\Pdo\PdoSiteRepository;
use Uphptime\Storage\Pdo\PostgreSqlDialect;
use Uphptime\Storage\Pdo\SqlDialect;
use Uphptime\Storage\PeerRepositoryInterface;
use Uphptime\Storage\PeerReputationRepositoryInterface;
use Uphptime\Storage\RollupRepositoryInterface;
use Uphptime\Storage\SiteRepositoryInterface;
use Uphptime\Support\AtomicWriter;
use Uphptime\Support\PathResolver;

return [
    AtomicWriter::class => autowire(),
    PdoConnectionFactory::class => autowire(),
    // Only resolved when storage_backend isn't "json" (PHP-DI resolves a factory lazily), so a
    // JSON-only install never needs a PDO driver loaded at all.
    PDO::class => factory(
        static fn(ContainerInterface $c): PDO => $c->get(PdoConnectionFactory::class)->create($c->get(Config::class)),
    ),
    SqlDialect::class => factory(static function (ContainerInterface $c): SqlDialect {
        return $c->get(Config::class)->storageBackend === 'pgsql' ? new PostgreSqlDialect() : new MySqlDialect();
    }),
    HistoryRepositoryInterface::class => factory(static function (ContainerInterface $c): HistoryRepositoryInterface {
        $config = $c->get(Config::class);
        if ($config->storageBackend === 'json') {
            return new JsonHistoryRepository($c->get('data.dir') . '/history');
        }

        return new PdoHistoryRepository($c->get(PDO::class), $c->get(SqlDialect::class), $config->timezone);
    }),
    PeerRepositoryInterface::class => factory(static function (ContainerInterface $c): PeerRepositoryInterface {
        if ($c->get(Config::class)->storageBackend === 'json') {
            return new JsonPeerRepository($c->get(AtomicWriter::class), $c->get('data.dir') . '/peers.json');
        }

        return new PdoPeerRepository($c->get(PDO::class), $c->get(SqlDialect::class));
    }),
    PeerReputationRepositoryInterface::class => factory(static function (ContainerInterface $c): PeerReputationRepositoryInterface {
        $config = $c->get(Config::class);

        if ($config->storageBackend === 'json') {
            return new JsonPeerReputationRepository($c->get(AtomicWriter::class), $c->get('data.dir') . '/peer_reputation.json', $config->peerReputationNeutralScore);
        }

        return new PdoPeerReputationRepository($c->get(PDO::class), $c->get(SqlDialect::class), $config->peerReputationNeutralScore);
    }),
    RollupRepositoryInterface::class => factory(static function (ContainerInterface $c): RollupRepositoryInterface {
        if ($c->get(Config::class)->storageBackend === 'json') {
            return new JsonRollupRepository($c->get(AtomicWriter::class), $c->get('data.dir') . '/rollups');
        }

        return new PdoRollupRepository($c->get(PDO::class), $c->get(SqlDialect::class));
    }),
    SiteRepositoryInterface::class => factory(static function (ContainerInterface $c): SiteRepositoryInterface {
        if ($c->get(Config::class)->storageBackend === 'json') {
            return new JsonSiteRepository($c->get(AtomicWriter::class), $c->get('data.dir') . '/sites.json');
        }

        return new PdoSiteRepository($c->get(PDO::class), $c->get(SqlDialect::class));
    }),
    // Resolves relative to the repo root for the common single-node case, but honors an absolute
    // data_dir as-is - a node pointed at its own config via UPHPTIME_CONFIG_PATH needs its own
    // isolated data directory, not always one hardcoded to this checkout's root.
    'data.dir' => factory(static function (ContainerInterface $c): string {
        $config = $c->get(Config::class);

        return PathResolver::resolve(dirname(__DIR__, 3), $config->dataDirectory);
    }),
];

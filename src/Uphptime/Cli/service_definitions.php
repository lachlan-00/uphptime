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
use Uphptime\Check\CheckRunner;
use Uphptime\Cli\CliApplication;
use Uphptime\Cli\Commands\CheckRunCommand;
use Uphptime\Cli\Commands\MaintenanceAddCommand;
use Uphptime\Cli\Commands\MaintenanceListCommand;
use Uphptime\Cli\Commands\MaintenanceRemoveCommand;
use Uphptime\Cli\Commands\PeersAddCommand;
use Uphptime\Cli\Commands\PeersAllowlistAddCommand;
use Uphptime\Cli\Commands\PeersAllowlistClearCommand;
use Uphptime\Cli\Commands\PeersAllowlistRemoveCommand;
use Uphptime\Cli\Commands\PeersKeysAddCommand;
use Uphptime\Cli\Commands\PeersKeysRetireCommand;
use Uphptime\Cli\Commands\PeersKeysRotateCommand;
use Uphptime\Cli\Commands\PeersListCommand;
use Uphptime\Cli\Commands\PeersRemoveCommand;
use Uphptime\Cli\Commands\PeersReputationResetCommand;
use Uphptime\Cli\Commands\PeersSetCommand;
use Uphptime\Cli\Commands\PingerRefreshCacheCommand;
use Uphptime\Cli\Commands\ReportCommand;
use Uphptime\Cli\Commands\SitesAddCommand;
use Uphptime\Cli\Commands\SitesListCommand;
use Uphptime\Cli\Commands\SitesRemoveCommand;
use Uphptime\Cli\Commands\StorageExportCommand;
use Uphptime\Cli\Commands\StorageImportCommand;
use Uphptime\Cli\Commands\StorageInstallCommand;
use Uphptime\Cli\Commands\StorageMigrateTimezoneCommand;
use Uphptime\Cli\ConsoleOutputInterface;
use Uphptime\Cli\PeerKeyOptionResolver;
use Uphptime\Cli\StreamConsoleOutput;
use Uphptime\Pinger\CacheRefreshRunner;
use Uphptime\Scheduling\Lockfile;
use Uphptime\Storage\Portability\StorageCopier;
use Uphptime\Storage\Portability\StorageWiper;

return [
    CheckRunCommand::class => factory(static function (ContainerInterface $c): CheckRunCommand {
        return new CheckRunCommand(
            $c->get(CheckRunner::class),
            new Lockfile($c->get('data.dir') . '/run.lock'),
            $c->get(ConsoleOutputInterface::class),
        );
    }),
    CliApplication::class => factory(static function (ContainerInterface $c): CliApplication {
        return new CliApplication([
            'sites:add' => $c->get(SitesAddCommand::class),
            'sites:list' => $c->get(SitesListCommand::class),
            'sites:remove' => $c->get(SitesRemoveCommand::class),
            'maintenance:add' => $c->get(MaintenanceAddCommand::class),
            'maintenance:list' => $c->get(MaintenanceListCommand::class),
            'maintenance:remove' => $c->get(MaintenanceRemoveCommand::class),
            'check:run' => $c->get(CheckRunCommand::class),
            'report' => $c->get(ReportCommand::class),
            'pinger:refresh-cache' => $c->get(PingerRefreshCacheCommand::class),
            'peers:add' => $c->get(PeersAddCommand::class),
            'peers:list' => $c->get(PeersListCommand::class),
            'peers:remove' => $c->get(PeersRemoveCommand::class),
            'peers:set' => $c->get(PeersSetCommand::class),
            'peers:keys:add' => $c->get(PeersKeysAddCommand::class),
            'peers:keys:rotate' => $c->get(PeersKeysRotateCommand::class),
            'peers:keys:retire' => $c->get(PeersKeysRetireCommand::class),
            'peers:allowlist:add' => $c->get(PeersAllowlistAddCommand::class),
            'peers:allowlist:remove' => $c->get(PeersAllowlistRemoveCommand::class),
            'peers:allowlist:clear' => $c->get(PeersAllowlistClearCommand::class),
            'peers:reputation:reset' => $c->get(PeersReputationResetCommand::class),
            'storage:install' => $c->get(StorageInstallCommand::class),
            'storage:export' => $c->get(StorageExportCommand::class),
            'storage:import' => $c->get(StorageImportCommand::class),
            'storage:migrate-timezone' => $c->get(StorageMigrateTimezoneCommand::class),
        ], $c->get(ConsoleOutputInterface::class));
    }),
    ConsoleOutputInterface::class => factory(static fn(): ConsoleOutputInterface => new StreamConsoleOutput(STDOUT, STDERR)),
    MaintenanceAddCommand::class => autowire(),
    MaintenanceListCommand::class => autowire(),
    MaintenanceRemoveCommand::class => autowire(),
    PeerKeyOptionResolver::class => autowire(),
    PeersAddCommand::class => autowire(),
    PeersAllowlistAddCommand::class => autowire(),
    PeersAllowlistClearCommand::class => autowire(),
    PeersAllowlistRemoveCommand::class => autowire(),
    PeersKeysAddCommand::class => autowire(),
    PeersKeysRetireCommand::class => autowire(),
    PeersKeysRotateCommand::class => autowire(),
    PeersListCommand::class => autowire(),
    PeersRemoveCommand::class => autowire(),
    PeersReputationResetCommand::class => autowire(),
    PeersSetCommand::class => autowire(),
    PingerRefreshCacheCommand::class => factory(static function (ContainerInterface $c): PingerRefreshCacheCommand {
        return new PingerRefreshCacheCommand(
            $c->get(CacheRefreshRunner::class),
            new Lockfile($c->get('data.dir') . '/pinger_refresh.lock'),
            $c->get(ConsoleOutputInterface::class),
        );
    }),
    ReportCommand::class => autowire(),
    SitesAddCommand::class => autowire(),
    SitesListCommand::class => autowire(),
    SitesRemoveCommand::class => autowire(),
    StorageCopier::class => autowire(),
    StorageExportCommand::class => autowire(),
    StorageImportCommand::class => autowire(),
    StorageInstallCommand::class => autowire(),
    StorageMigrateTimezoneCommand::class => autowire(),
    StorageWiper::class => autowire(),
];

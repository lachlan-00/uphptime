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

namespace Uphptime;

use DI\ContainerBuilder;

use function DI\factory;

use Psr\Container\ContainerInterface;
use Uphptime\Config\Config;
use Uphptime\Config\ConfigLoader;

/**
 * Aggregates one service_definitions.php per domain, mirroring Ampache's DicBuilder. $configPath,
 * when given, overrides the env-var-based default so a test or the two-node harness can point a
 * container at an isolated config/data tree without mutating the process environment.
 */
final class DicBuilder
{
    public function build(?string $configPath = null): ContainerInterface
    {
        $builder = new ContainerBuilder();
        $builder->addDefinitions(
            __DIR__ . '/Config/service_definitions.php',
            __DIR__ . '/Storage/service_definitions.php',
            __DIR__ . '/Check/service_definitions.php',
            __DIR__ . '/Peer/service_definitions.php',
            __DIR__ . '/Pinger/service_definitions.php',
            __DIR__ . '/Api/service_definitions.php',
            __DIR__ . '/Cli/service_definitions.php',
        );

        if ($configPath !== null) {
            $builder->addDefinitions([
                Config::class => factory(static fn(): Config => (new ConfigLoader())->load($configPath)),
            ]);
        }

        return $builder->build();
    }
}

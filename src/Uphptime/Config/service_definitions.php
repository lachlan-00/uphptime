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

use function DI\factory;

use Uphptime\Config\Config;
use Uphptime\Config\ConfigLoader;
use Uphptime\Support\ClockInterface;
use Uphptime\Support\NetworkStackInterface;
use Uphptime\Support\RandomInterface;
use Uphptime\Support\SystemClock;
use Uphptime\Support\SystemNetworkStack;
use Uphptime\Support\SystemRandom;

// Default Config resolution: UPHPTIME_CONFIG_PATH if set, else the repo's config/node.json. A
// caller that needs a specific path (DicBuilder::build($path), tests) overrides this one entry
// rather than relying on the environment.
return [
    ClockInterface::class => factory(static fn(Config $config): ClockInterface => new SystemClock($config->timezone)),
    NetworkStackInterface::class => factory(static fn(): NetworkStackInterface => new SystemNetworkStack()),
    RandomInterface::class => factory(static fn(): RandomInterface => new SystemRandom()),
    Config::class => factory(static function (): Config {
        $configPath = getenv('UPHPTIME_CONFIG_PATH');
        if (!is_string($configPath) || $configPath === '') {
            $configPath = dirname(__DIR__, 3) . '/config/node.json';
        }

        return (new ConfigLoader())->load($configPath);
    }),
];

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

namespace Uphptime\Tests;

use PHPUnit\Framework\TestCase;
use Uphptime\Api\Dispatcher;
use Uphptime\Api\HmacAuthMiddleware;
use Uphptime\Check\CheckRunner;
use Uphptime\Cli\CliApplication;
use Uphptime\DicBuilder;

final class DicBuilderTest extends TestCase
{
    private string $configPath;

    public function testContainerResolvesCheckRunner(): void
    {
        $container = (new DicBuilder())->build($this->configPath);

        self::assertInstanceOf(CheckRunner::class, $container->get(CheckRunner::class));
    }

    public function testContainerResolvesCliApplication(): void
    {
        $container = (new DicBuilder())->build($this->configPath);

        self::assertInstanceOf(CliApplication::class, $container->get(CliApplication::class));
    }

    public function testContainerResolvesDispatcherAndAuthMiddleware(): void
    {
        $container = (new DicBuilder())->build($this->configPath);

        self::assertInstanceOf(Dispatcher::class, $container->get(Dispatcher::class));
        self::assertInstanceOf(HmacAuthMiddleware::class, $container->get(HmacAuthMiddleware::class));
    }

    public function testTwoBuildsDoNotShareState(): void
    {
        $containerA = (new DicBuilder())->build($this->configPath);
        $containerB = (new DicBuilder())->build($this->configPath);

        self::assertNotSame($containerA->get(CheckRunner::class), $containerB->get(CheckRunner::class));
    }

    protected function setUp(): void
    {
        $this->configPath = dirname(__DIR__, 2) . '/config/node.json.dist';
    }
}

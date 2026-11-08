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

namespace Uphptime\Api;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use RuntimeException;

/**
 * A fixed action-name to ActionInterface dispatcher, mirroring Ampache's Method/ApiHandler pattern
 * so the HTTP route is a thin transport detail and new actions register here, not in Slim.
 */
final class Dispatcher
{
    /** @param array<string, ActionInterface> $actions */
    public function __construct(private readonly array $actions) {}

    public function dispatch(string $actionName, ServerRequestInterface $request): ResponseInterface
    {
        if (!isset($this->actions[$actionName])) {
            throw new RuntimeException(sprintf('Unknown action "%s".', $actionName));
        }

        return $this->actions[$actionName]->handle($request);
    }
}

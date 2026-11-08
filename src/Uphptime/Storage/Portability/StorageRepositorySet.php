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

namespace Uphptime\Storage\Portability;

use Uphptime\Storage\HistoryRepositoryInterface;
use Uphptime\Storage\PeerRepositoryInterface;
use Uphptime\Storage\PeerReputationRepositoryInterface;
use Uphptime\Storage\RollupRepositoryInterface;
use Uphptime\Storage\SiteRepositoryInterface;

/**
 * One full backend's worth of repositories, bundled so StorageCopier/StorageWiper take one argument
 * per side instead of five - a backend-agnostic handle, since every property is an interface.
 */
final class StorageRepositorySet
{
    public function __construct(
        public readonly SiteRepositoryInterface $sites,
        public readonly PeerRepositoryInterface $peers,
        public readonly PeerReputationRepositoryInterface $peerReputations,
        public readonly HistoryRepositoryInterface $history,
        public readonly RollupRepositoryInterface $rollups,
    ) {}

    // Cheap enough to check directly: history/rollups can't meaningfully exist without a site to own them.
    public function isEmpty(): bool
    {
        return $this->sites->all() === [] && $this->peers->all() === [] && $this->peerReputations->all() === [];
    }
}

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

namespace Uphptime\Tests\Pinger;

use Uphptime\Peer\Peer;
use Uphptime\Pinger\DelegatedCheckExecutorInterface;
use Uphptime\Pinger\DelegatedCheckOutcome;
use Uphptime\Site\Site;

/**
 * A scripted DelegatedCheckExecutorInterface double that counts calls, so a test can assert a
 * probe did or did not happen - never touches a real socket.
 */
final class FakeDelegatedCheckExecutor implements DelegatedCheckExecutorInterface
{
    public int $callCount = 0;

    /** @var DelegatedCheckOutcome[] */
    private array $queue;

    /** @param DelegatedCheckOutcome[] $outcomes replayed in order; the last one repeats once exhausted */
    public function __construct(array $outcomes)
    {
        $this->queue = $outcomes;
    }

    public function execute(Site $site, ?Peer $callingPeer, float $timeoutSeconds): DelegatedCheckOutcome
    {
        $this->callCount++;

        return count($this->queue) > 1 ? array_shift($this->queue) : $this->queue[0];
    }
}

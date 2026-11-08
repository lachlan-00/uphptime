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

namespace Uphptime\Tests\Api\Client;

use RuntimeException;
use Uphptime\Api\Client\DelegatedCheckFailedException;
use Uphptime\Api\Client\DelegatedCheckResult;
use Uphptime\Api\Client\PeerCheckClientInterface;
use Uphptime\Peer\Peer;
use Uphptime\Site\Site;

/**
 * A scripted PeerCheckClientInterface double; never touches a real socket.
 */
final class FakePeerCheckClient implements PeerCheckClientInterface
{
    /** @var array<string, DelegatedCheckResult|null>|null */
    private ?array $byPeerId = null;

    private ?DelegatedCheckResult $result = null;
    private bool $shouldFail              = false;

    // Scripts a distinct outcome per peer id, keyed the same way Peer::id is stored; a null value
    // scripts that specific peer as non-responsive (throws), letting one test exercise a multi-peer
    // scenario (confirmation, canary, a mix of responsive and unresponsive peers) deterministically.
    /** @param array<string, DelegatedCheckResult|null> $resultsByPeerId */
    public static function byPeerId(array $resultsByPeerId): self
    {
        $client           = new self();
        $client->byPeerId = $resultsByPeerId;

        return $client;
    }

    public static function failing(): self
    {
        $client             = new self();
        $client->shouldFail = true;

        return $client;
    }

    public static function returning(DelegatedCheckResult $result): self
    {
        $client         = new self();
        $client->result = $result;

        return $client;
    }

    public function requestCheck(Peer $peer, Site $site, float $timeoutSeconds): DelegatedCheckResult
    {
        if ($this->byPeerId !== null) {
            if (!array_key_exists($peer->id, $this->byPeerId)) {
                throw new RuntimeException(sprintf('FakePeerCheckClient has no result configured for peer "%s".', $peer->id));
            }

            return $this->byPeerId[$peer->id] ?? throw new DelegatedCheckFailedException('fake delegated check failure for ' . $peer->id);
        }

        if ($this->shouldFail) {
            throw new DelegatedCheckFailedException('fake delegated check failure');
        }

        return $this->result ?? throw new RuntimeException('FakePeerCheckClient has no result configured.');
    }
}

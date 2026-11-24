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

namespace Uphptime\Check;

use Uphptime\Api\Client\DelegatedCheckFailedException;
use Uphptime\Api\Client\PeerCheckClientInterface;
use Uphptime\Peer\PeerReputationScorer;
use Uphptime\Peer\PeerSelectorInterface;
use Uphptime\Site\Site;
use Uphptime\Storage\PeerReputationRepositoryInterface;
use Uphptime\Support\ClockInterface;

/**
 * Tries up to max_delegates_per_acquisition distinct peers for one conclusive Pass or Fail.
 * A non-response excludes that peer and tries the next; the first valid response wins.
 */
final class PeerResultAcquirer
{
    public function __construct(
        private readonly PeerSelectorInterface $peerSelector,
        private readonly PeerCheckClientInterface $peerCheckClient,
        private readonly PeerReputationRepositoryInterface $reputationRepository,
        private readonly PeerReputationScorer $scorer,
        private readonly ClockInterface $clock,
        private readonly AttestationVerifier $attestationVerifier,
        private readonly int $maxAttempts,
    ) {}

    /** @param string[] $excludePeerIds */
    public function acquire(Site $site, array $excludePeerIds, float $timeoutSeconds): ?AcquiredPeerResult
    {
        $excluded = $excludePeerIds;

        for ($attempt = 0; $attempt < $this->maxAttempts; $attempt++) {
            $peer = $this->peerSelector->pick($excluded);
            if (!$peer instanceof \Uphptime\Peer\Peer) {
                return null;
            }

            $excluded[] = $peer->id;

            try {
                $delegated = $this->peerCheckClient->requestCheck($peer, $site, $timeoutSeconds);
            } catch (DelegatedCheckFailedException) {
                $this->recordEvent($peer->id, good: false);
                continue;
            }

            if ($delegated->result->outcome === CheckOutcome::Error) {
                // A valid signed reply that settles nothing (e.g. an egress policy refusal) counts as a good peer response.
                $this->recordEvent($peer->id, good: true);
                continue;
            }

            // The pinger's self-reported `attestation` is never trusted; only the owner's real challenge token counts.
            $verdict = $this->attestationVerifier->verify($site, $delegated->result, $delegated->evidence);
            if (!$verdict->isValid) {
                $this->recordEvent($peer->id, good: false);
                continue;
            }

            $this->recordEvent($peer->id, good: true);

            return new AcquiredPeerResult($peer, $delegated->verifiedKeyId, $delegated->result, $delegated->evidence, $verdict->attestation);
        }

        return null;
    }

    private function recordEvent(string $peerId, bool $good): void
    {
        $now        = $this->clock->now();
        $reputation = $this->reputationRepository->get($peerId);
        $reputation = $good ? $this->scorer->recordGoodResponse($reputation, $now) : $this->scorer->recordNonResponse($reputation, $now);

        $this->reputationRepository->save($reputation);
    }
}

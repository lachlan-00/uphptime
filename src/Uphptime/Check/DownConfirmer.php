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
use Uphptime\Config\Config;
use Uphptime\Peer\PeerReputationScorer;
use Uphptime\Peer\PeerSelectorInterface;
use Uphptime\Site\Site;
use Uphptime\Storage\PeerReputationRepositoryInterface;
use Uphptime\Support\ClockInterface;

/**
 * Confirms a primary Fail via a genuinely different peer before anything reaches the state machine.
 * With no different peer available, or none that returns a conclusive answer within the attempt
 * cap, a self-check stands in as the confirmer (the same vantage-point trade-off milestone 1's
 * zero-peer fallback already makes). An Error confirming reply settles
 * nothing, so it is treated like a non-response: keep trying another peer.
 */
final class DownConfirmer
{
    /** @param array<string, CheckerInterface> $checkers */
    public function __construct(
        private readonly PeerSelectorInterface $peerSelector,
        private readonly PeerCheckClientInterface $peerCheckClient,
        private readonly PeerReputationRepositoryInterface $reputationRepository,
        private readonly PeerReputationScorer $scorer,
        private readonly ClockInterface $clock,
        private readonly Config $config,
        private readonly AttestationVerifier $attestationVerifier,
        private readonly array $checkers,
        private readonly int $maxAttempts,
    ) {}

    public function confirm(Site $site, AcquiredPeerResult $primary, float $timeoutSeconds): ConfirmationOutcome
    {
        if ($this->peerSelector->pick([(string) $primary->peer?->id]) === null) {
            return $this->confirmBySelfCheck($site);
        }

        $excluded = [(string) $primary->peer?->id];

        for ($attempt = 0; $attempt < $this->maxAttempts; $attempt++) {
            $peer = $this->peerSelector->pick($excluded);
            if ($peer === null) {
                break;
            }

            $excluded[] = $peer->id;

            try {
                $delegated = $this->peerCheckClient->requestCheck($peer, $site, $timeoutSeconds);
            } catch (DelegatedCheckFailedException) {
                $this->recordEvent($peer->id, good: false);
                continue;
            }

            if ($delegated->result->outcome === CheckOutcome::Error) {
                // A valid signed reply that settles nothing - still a good response, keep trying.
                $this->recordEvent($peer->id, good: true);
                continue;
            }

            // The pinger's own self-reported `attestation` is never trusted (see AttestationVerifier).
            $verdict = $this->attestationVerifier->verify($site, $delegated->result, $delegated->evidence);
            if (!$verdict->isValid) {
                $this->recordEvent($peer->id, good: false);
                continue;
            }

            $this->recordEvent($peer->id, good: true);

            if ($delegated->result->outcome === CheckOutcome::Fail) {
                return ConfirmationOutcome::resolved($delegated->result, 'peer:' . $delegated->verifiedKeyId, $delegated->evidence, $verdict->attestation, $peer);
            }

            // Disagreement: the confirming peer passed where the primary failed. Routine and
            // expected - this is exactly what confirmation exists to surface - so it is scored
            // gently and never alone drives either peer toward exclusion.
            $this->recordDisagreement((string) $primary->peer?->id);
            $this->recordDisagreement($peer->id);

            return ConfirmationOutcome::resolved($delegated->result, 'peer:' . $delegated->verifiedKeyId, $delegated->evidence, $verdict->attestation, $peer);
        }

        // No other peer could settle it (every attempt failed or errored): this node checks too.
        return $this->confirmBySelfCheck($site);
    }

    // The primary peer already reported a failure from its own vantage point, so a local check that
    // cannot connect is independent corroboration - not an unverifiable local network problem.
    // Only a local Error (nothing could be measured) leaves the failure unconfirmed.
    private function confirmBySelfCheck(Site $site): ConfirmationOutcome
    {
        $result = $this->selfCheck($site);
        if ($result->outcome === CheckOutcome::Error) {
            return ConfirmationOutcome::unconfirmed();
        }

        return ConfirmationOutcome::resolved($result, 'self', null, 'none');
    }

    private function recordDisagreement(string $peerId): void
    {
        if ($peerId === '') {
            return;
        }

        $now        = $this->clock->now();
        $reputation = $this->reputationRepository->get($peerId);
        $this->reputationRepository->save($this->scorer->recordConfirmationDisagreement($reputation, $now));
    }

    private function recordEvent(string $peerId, bool $good): void
    {
        $now        = $this->clock->now();
        $reputation = $this->reputationRepository->get($peerId);
        $reputation = $good ? $this->scorer->recordGoodResponse($reputation, $now) : $this->scorer->recordNonResponse($reputation, $now);

        $this->reputationRepository->save($reputation);
    }

    private function selfCheck(Site $site): CheckResult
    {
        $checker = $this->checkers[$site->checkType->value];

        try {
            return $checker->check($site, (float) $this->config->requestTimeoutSeconds);
        } catch (CheckConfigurationException $exception) {
            return CheckResult::error($exception->getMessage());
        }
    }
}

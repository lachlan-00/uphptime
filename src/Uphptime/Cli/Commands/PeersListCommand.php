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

namespace Uphptime\Cli\Commands;

use DateTimeImmutable;
use DateTimeInterface;
use Uphptime\Cli\CommandInterface;
use Uphptime\Cli\ConsoleOutputInterface;
use Uphptime\Peer\Peer;
use Uphptime\Peer\PeerKey;
use Uphptime\Peer\PeerReputation;
use Uphptime\Storage\PeerRepositoryInterface;
use Uphptime\Storage\PeerReputationRepositoryInterface;
use Uphptime\Support\ClockInterface;

/**
 * Lists every peer with its keys (never their secrets) and its current reputation, pulling the latter
 * from PeerReputationRepositoryInterface so an unscored peer naturally shows the neutral defaults.
 */
final class PeersListCommand implements CommandInterface
{
    public function __construct(
        private readonly PeerRepositoryInterface $peerRepository,
        private readonly PeerReputationRepositoryInterface $reputationRepository,
        private readonly ClockInterface $clock,
        private readonly ConsoleOutputInterface $output,
    ) {}

    public function run(array $args): int
    {
        $now  = $this->clock->now();
        $rows = array_map(fn(Peer $peer): array => $this->peerToArray($peer, $now), $this->peerRepository->all());

        $this->output->writeLine(json_encode($rows, JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT));

        return 0;
    }

    /** @return array<string, mixed> */
    private function keyToArray(PeerKey $key): array
    {
        return [
            'key_id' => $key->keyId,
            'outbound' => $key->outbound,
            'inbound' => $key->inbound,
            'enabled' => $key->enabled,
            'created_at' => $key->createdAt->format(DateTimeInterface::ATOM),
        ];
    }

    /** @return array<string, mixed> */
    private function peerToArray(Peer $peer, DateTimeImmutable $now): array
    {
        return [
            'id' => $peer->id,
            'label' => $peer->label,
            'base_url' => $peer->baseUrl,
            'enabled' => $peer->enabled,
            'allow_outbound_delegation' => $peer->allowOutboundDelegation,
            'rate_limit_per_minute' => $peer->rateLimitPerMinute,
            'target_allowlist' => $peer->targetAllowlist,
            'keys' => array_map(fn(PeerKey $key): array => $this->keyToArray($key), $peer->keys),
            'reputation' => $this->reputationToArray($this->reputationRepository->get($peer->id), $now),
        ];
    }

    /** @return array<string, mixed> */
    private function reputationToArray(PeerReputation $reputation, DateTimeImmutable $now): array
    {
        return [
            'score' => $reputation->score,
            'consecutive_exclusions' => $reputation->consecutiveExclusions,
            'excluded' => $reputation->isExcluded($now),
            'excluded_until' => $reputation->excludedUntil?->format(DateTimeInterface::ATOM),
            'last_event_at' => $reputation->lastEventAt?->format(DateTimeInterface::ATOM),
            'total_events' => $reputation->totalEvents,
        ];
    }
}

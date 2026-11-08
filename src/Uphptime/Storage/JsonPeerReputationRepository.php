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

namespace Uphptime\Storage;

use DateTimeImmutable;
use DateTimeInterface;
use Uphptime\Peer\PeerReputation;
use Uphptime\Support\AtomicWriter;

/**
 * peer_reputation.json as a single atomically-rewritten file, the same pattern as JsonPeerRepository.
 */
final class JsonPeerReputationRepository implements PeerReputationRepositoryInterface
{
    public function __construct(
        private readonly AtomicWriter $writer,
        private readonly string $path,
        private readonly float $neutralScore,
    ) {}

    public function all(): array
    {
        return array_map(
            fn(array $data): PeerReputation => $this->fromArray($data),
            $this->rowsFrom($this->decodeFile($this->writer->read($this->path))),
        );
    }

    public function get(string $peerId): PeerReputation
    {
        foreach ($this->all() as $reputation) {
            if ($reputation->peerId === $peerId) {
                return $reputation;
            }
        }

        return PeerReputation::neutral($peerId, $this->neutralScore);
    }

    // readModifyWrite() holds one lock across the whole read-decide-write sequence, so two concurrent
    // removes/saves can never race on a stale read.
    public function remove(string $peerId): void
    {
        $this->writer->readModifyWrite($this->path, function (?string $current) use ($peerId): string {
            $rows = array_values(array_filter(
                $this->rowsFrom($this->decodeFile($current)),
                static fn(array $existing): bool => $existing['peer_id'] !== $peerId,
            ));

            return $this->encodeFile(['schema_version' => 1, 'reputations' => $rows]);
        });
    }

    public function save(PeerReputation $reputation): void
    {
        $this->writer->readModifyWrite($this->path, function (?string $current) use ($reputation): string {
            $rows  = $this->rowsFrom($this->decodeFile($current));
            $found = false;

            foreach ($rows as $index => $existing) {
                if ($existing['peer_id'] === $reputation->peerId) {
                    $rows[$index] = $this->toArray($reputation);
                    $found        = true;
                    break;
                }
            }

            if (!$found) {
                $rows[] = $this->toArray($reputation);
            }

            return $this->encodeFile(['schema_version' => 1, 'reputations' => $rows]);
        });
    }

    /** @return array<string, mixed> */
    private function decodeFile(?string $contents): array
    {
        if ($contents === null) {
            return ['schema_version' => 1, 'reputations' => []];
        }

        $decoded = json_decode($contents, true);

        return is_array($decoded) ? $decoded : ['schema_version' => 1, 'reputations' => []];
    }

    /** @param array<string, mixed> $data */
    private function encodeFile(array $data): string
    {
        return json_encode($data, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR) . "\n";
    }

    /** @param array<string, mixed> $data */
    private function fromArray(array $data): PeerReputation
    {
        return new PeerReputation(
            peerId: (string) $data['peer_id'],
            score: (float) $data['score'],
            consecutiveExclusions: (int) $data['consecutive_exclusions'],
            excludedUntil: $data['excluded_until'] !== null ? new DateTimeImmutable((string) $data['excluded_until']) : null,
            lastEventAt: $data['last_event_at'] !== null ? new DateTimeImmutable((string) $data['last_event_at']) : null,
            totalEvents: (int) $data['total_events'],
        );
    }

    /**
     * @param array<string, mixed> $data
     * @return array<int, array<string, mixed>>
     */
    private function rowsFrom(array $data): array
    {
        $rawRows = $data['reputations'] ?? [];
        if (!is_array($rawRows)) {
            return [];
        }

        $rows = [];
        foreach ($rawRows as $rawRow) {
            if (is_array($rawRow)) {
                $rows[] = $rawRow;
            }
        }

        return $rows;
    }

    /** @return array<string, mixed> */
    private function toArray(PeerReputation $reputation): array
    {
        return [
            'peer_id' => $reputation->peerId,
            'score' => $reputation->score,
            'consecutive_exclusions' => $reputation->consecutiveExclusions,
            'excluded_until' => $reputation->excludedUntil?->format(DateTimeInterface::ATOM),
            'last_event_at' => $reputation->lastEventAt?->format(DateTimeInterface::ATOM),
            'total_events' => $reputation->totalEvents,
        ];
    }
}

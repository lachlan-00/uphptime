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
use Uphptime\Peer\Peer;
use Uphptime\Peer\PeerKey;
use Uphptime\Support\AtomicWriter;

/**
 * peers.json as a single atomically-rewritten file, the same pattern as JsonSiteRepository.
 */
final class JsonPeerRepository implements PeerRepositoryInterface
{
    public function __construct(
        private readonly AtomicWriter $writer,
        private readonly string $path,
    ) {}

    public function all(): array
    {
        return array_map(
            fn(array $data): Peer => $this->peerFromArray($data),
            $this->peersFrom($this->decodeFile($this->writer->read($this->path))),
        );
    }

    public function enabledForDelegation(): array
    {
        return array_values(array_filter(
            $this->all(),
            static fn(Peer $peer): bool => $peer->enabled && $peer->allowOutboundDelegation && $peer->baseUrl !== null && $peer->activeOutboundKeyId() !== null,
        ));
    }

    public function find(string $id): ?Peer
    {
        foreach ($this->all() as $peer) {
            if ($peer->id === $id) {
                return $peer;
            }
        }

        return null;
    }

    public function findByInboundKeyId(string $keyId): ?array
    {
        foreach ($this->all() as $peer) {
            $key = $peer->findInboundKey($keyId);
            if ($key !== null) {
                return ['peer' => $peer, 'key' => $key];
            }
        }

        return null;
    }

    // readModifyWrite() holds one lock across the whole read-decide-write sequence, so two concurrent
    // removes/saves can never race on a stale read (the lost-update bug a separate read()+write() has).
    public function remove(string $id): void
    {
        $this->writer->readModifyWrite($this->path, function (?string $current) use ($id): string {
            $peers = array_values(array_filter(
                $this->peersFrom($this->decodeFile($current)),
                static fn(array $existing): bool => $existing['id'] !== $id,
            ));

            return $this->encodeFile(['schema_version' => 1, 'peers' => $peers]);
        });
    }

    public function save(Peer $peer): void
    {
        $this->writer->readModifyWrite($this->path, function (?string $current) use ($peer): string {
            $peers = $this->peersFrom($this->decodeFile($current));
            $found = false;

            foreach ($peers as $index => $existing) {
                if ($existing['id'] === $peer->id) {
                    $peers[$index] = $this->peerToArray($peer);
                    $found         = true;
                    break;
                }
            }

            if (!$found) {
                $peers[] = $this->peerToArray($peer);
            }

            return $this->encodeFile(['schema_version' => 1, 'peers' => $peers]);
        });
    }

    /** @return array<string, mixed> */
    private function decodeFile(?string $contents): array
    {
        if ($contents === null) {
            return ['schema_version' => 1, 'peers' => []];
        }

        $decoded = json_decode($contents, true);

        return is_array($decoded) ? $decoded : ['schema_version' => 1, 'peers' => []];
    }

    /** @param array{schema_version: int, peers: array<int, array<string, mixed>>} $data */
    private function encodeFile(array $data): string
    {
        return json_encode($data, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR) . "\n";
    }

    /** @param array<string, mixed> $data */
    private function keyFromArray(array $data): PeerKey
    {
        return new PeerKey(
            keyId: (string) $data['key_id'],
            secret: (string) base64_decode((string) $data['secret'], true),
            outbound: (bool) $data['outbound'],
            inbound: (bool) $data['inbound'],
            enabled: (bool) $data['enabled'],
            createdAt: new DateTimeImmutable((string) $data['created_at']),
        );
    }

    /** @return array<string, mixed> */
    private function keyToArray(PeerKey $key): array
    {
        return [
            'key_id' => $key->keyId,
            'secret' => base64_encode($key->secret),
            'outbound' => $key->outbound,
            'inbound' => $key->inbound,
            'enabled' => $key->enabled,
            'created_at' => $key->createdAt->format(DateTimeInterface::ATOM),
        ];
    }

    /** @param array<string, mixed> $data */
    private function peerFromArray(array $data): Peer
    {
        $rawKeys = is_array($data['keys'] ?? null) ? $data['keys'] : [];
        $keys    = [];
        foreach ($rawKeys as $rawKey) {
            if (is_array($rawKey)) {
                $keys[] = $this->keyFromArray($rawKey);
            }
        }

        $rawAllowlist = is_array($data['target_allowlist'] ?? null) ? $data['target_allowlist'] : [];
        $allowlist    = array_map(static fn(mixed $item): string => (string) $item, $rawAllowlist);

        return new Peer(
            id: (string) $data['id'],
            label: (string) $data['label'],
            baseUrl: $data['base_url'] !== null ? (string) $data['base_url'] : null,
            enabled: (bool) $data['enabled'],
            allowOutboundDelegation: (bool) $data['allow_outbound_delegation'],
            rateLimitPerMinute: $data['rate_limit_per_minute'] !== null ? (int) $data['rate_limit_per_minute'] : null,
            targetAllowlist: $allowlist,
            keys: $keys,
        );
    }

    /**
     * @param array<string, mixed> $data
     * @return array<int, array<string, mixed>>
     */
    private function peersFrom(array $data): array
    {
        $rawPeers = $data['peers'] ?? [];
        if (!is_array($rawPeers)) {
            return [];
        }

        $peers = [];
        foreach ($rawPeers as $rawPeer) {
            if (is_array($rawPeer)) {
                $peers[] = $rawPeer;
            }
        }

        return $peers;
    }

    /** @return array<string, mixed> */
    private function peerToArray(Peer $peer): array
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
        ];
    }
}

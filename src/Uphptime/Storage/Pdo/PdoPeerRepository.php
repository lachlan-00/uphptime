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

namespace Uphptime\Storage\Pdo;

use PDO;
use PDOException;
use Uphptime\Peer\Peer;
use Uphptime\Peer\PeerKey;
use Uphptime\Storage\PeerRepositoryInterface;

/**
 * Peers in one table, their variable-length keys and target allowlist each in their own child table
 * (ON DELETE CASCADE) so remove() is one statement and insertion order survives via "ORDER BY id ASC".
 */
final class PdoPeerRepository implements PeerRepositoryInterface
{
    use PdoAccess;

    public function __construct(
        private readonly PDO $pdo,
        private readonly SqlDialect $dialect,
    ) {}

    public function all(): array
    {
        return array_map(fn(array $row): Peer => $this->hydrate($row), $this->query('SELECT * FROM peers ORDER BY id ASC'));
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
        $rows = $this->query('SELECT * FROM peers WHERE id = ?', [$id]);

        return $rows === [] ? null : $this->hydrate($rows[0]);
    }

    public function findByInboundKeyId(string $keyId): ?array
    {
        $rows = $this->query(
            'SELECT DISTINCT p.* FROM peers p INNER JOIN peer_keys k ON k.peer_id = p.id '
            . 'WHERE k.key_id = ? AND k.inbound = ? AND k.enabled = ? ORDER BY p.id ASC',
            [$keyId, 1, 1],
        );

        foreach ($rows as $row) {
            $peer = $this->hydrate($row);
            $key  = $peer->findInboundKey($keyId);
            if ($key !== null) {
                return ['peer' => $peer, 'key' => $key];
            }
        }

        return null;
    }

    public function remove(string $id): void
    {
        $this->execute('DELETE FROM peers WHERE id = ?', [$id]);
    }

    // One transaction: upsert the peer row, then fully replace its keys and allowlist, so a
    // concurrent reader (default READ COMMITTED on both engines) never sees a half-replaced key set.
    public function save(Peer $peer): void
    {
        try {
            $this->pdo->beginTransaction();
            $this->upsertPeerRow($peer);
            $this->replaceKeys($peer);
            $this->replaceAllowlist($peer);
            $this->pdo->commit();
        } catch (PDOException $exception) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }

            PdoExceptionTranslator::translate($exception);
        }
    }

    /** @param array<string, mixed> $row */
    private function hydrate(array $row): Peer
    {
        $peerId = (string) $row['id'];

        $keys      = array_map(
            fn(array $keyRow): PeerKey => $this->keyFromRow($keyRow),
            $this->query('SELECT * FROM peer_keys WHERE peer_id = ? ORDER BY id ASC', [$peerId]),
        );
        $allowlist = array_map(
            static fn(array $allowlistRow): string => (string) $allowlistRow['pattern'],
            $this->query('SELECT pattern FROM peer_target_allowlist WHERE peer_id = ? ORDER BY id ASC', [$peerId]),
        );

        return new Peer(
            id: $peerId,
            label: (string) $row['label'],
            baseUrl: $row['base_url'],
            enabled: $this->dialect->decodeBool($row['enabled']),
            allowOutboundDelegation: $this->dialect->decodeBool($row['allow_outbound_delegation']),
            rateLimitPerMinute: $row['rate_limit_per_minute'] !== null ? (int) $row['rate_limit_per_minute'] : null,
            targetAllowlist: $allowlist,
            keys: $keys,
        );
    }

    /** @param array<string, mixed> $row */
    private function keyFromRow(array $row): PeerKey
    {
        return new PeerKey(
            keyId: (string) $row['key_id'],
            secret: (string) base64_decode((string) $row['secret_b64'], true),
            outbound: $this->dialect->decodeBool($row['outbound']),
            inbound: $this->dialect->decodeBool($row['inbound']),
            enabled: $this->dialect->decodeBool($row['enabled']),
            createdAt: PdoTimestamp::parse((string) $row['created_at']),
        );
    }

    private function replaceAllowlist(Peer $peer): void
    {
        $this->rawExecute('DELETE FROM peer_target_allowlist WHERE peer_id = ?', [$peer->id]);

        foreach ($peer->targetAllowlist as $pattern) {
            $this->rawExecute('INSERT INTO peer_target_allowlist (peer_id, pattern) VALUES (?, ?)', [$peer->id, $pattern]);
        }
    }

    private function replaceKeys(Peer $peer): void
    {
        $this->rawExecute('DELETE FROM peer_keys WHERE peer_id = ?', [$peer->id]);

        foreach ($peer->keys as $key) {
            $this->rawExecute(
                'INSERT INTO peer_keys (peer_id, key_id, secret_b64, outbound, inbound, enabled, created_at) VALUES (?, ?, ?, ?, ?, ?, ?)',
                [
                    $peer->id,
                    $key->keyId,
                    base64_encode($key->secret),
                    $key->outbound ? 1 : 0,
                    $key->inbound ? 1 : 0,
                    $key->enabled ? 1 : 0,
                    PdoTimestamp::format($key->createdAt),
                ],
            );
        }
    }

    private function upsertPeerRow(Peer $peer): void
    {
        $columns = ['id', 'label', 'base_url', 'enabled', 'allow_outbound_delegation', 'rate_limit_per_minute'];
        $update  = ['label', 'base_url', 'enabled', 'allow_outbound_delegation', 'rate_limit_per_minute'];
        $sql     = sprintf(
            'INSERT INTO peers (%s) VALUES (%s)',
            implode(', ', $columns),
            implode(', ', array_fill(0, count($columns), '?')),
        ) . $this->dialect->upsertSuffix($columns, ['id'], $update);

        $this->rawExecute($sql, [
            $peer->id,
            $peer->label,
            $peer->baseUrl,
            $peer->enabled ? 1 : 0,
            $peer->allowOutboundDelegation ? 1 : 0,
            $peer->rateLimitPerMinute,
        ]);
    }
}

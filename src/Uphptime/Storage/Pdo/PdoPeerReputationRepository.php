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
use Uphptime\Peer\PeerReputation;
use Uphptime\Storage\PeerReputationRepositoryInterface;

/**
 * All scalar, one row per peer, deliberately with no foreign key to peers - get() must return a
 * neutral record for any peer id regardless of whether a peers row exists, same as the JSON backend.
 * save() is a plain blind-overwrite upsert, not stronger than the JSON backend's own already-blind
 * overwrite behavior.
 */
final class PdoPeerReputationRepository implements PeerReputationRepositoryInterface
{
    use PdoAccess;

    private const COLUMNS = ['peer_id', 'score', 'consecutive_exclusions', 'excluded_until', 'last_event_at', 'total_events'];

    public function __construct(
        private readonly PDO $pdo,
        private readonly SqlDialect $dialect,
        private readonly float $neutralScore,
    ) {}

    public function all(): array
    {
        return array_map(fn(array $row): PeerReputation => $this->fromRow($row), $this->query('SELECT * FROM peer_reputation ORDER BY peer_id ASC'));
    }

    public function get(string $peerId): PeerReputation
    {
        $rows = $this->query('SELECT * FROM peer_reputation WHERE peer_id = ?', [$peerId]);

        return $rows === [] ? PeerReputation::neutral($peerId, $this->neutralScore) : $this->fromRow($rows[0]);
    }

    public function remove(string $peerId): void
    {
        $this->execute('DELETE FROM peer_reputation WHERE peer_id = ?', [$peerId]);
    }

    public function save(PeerReputation $reputation): void
    {
        $update = array_values(array_diff(self::COLUMNS, ['peer_id']));
        $sql    = sprintf(
            'INSERT INTO peer_reputation (%s) VALUES (%s)',
            implode(', ', self::COLUMNS),
            implode(', ', array_fill(0, count(self::COLUMNS), '?')),
        ) . $this->dialect->upsertSuffix(self::COLUMNS, ['peer_id'], $update);

        $this->execute($sql, [
            $reputation->peerId,
            $reputation->score,
            $reputation->consecutiveExclusions,
            $reputation->excludedUntil !== null ? PdoTimestamp::format($reputation->excludedUntil) : null,
            $reputation->lastEventAt !== null ? PdoTimestamp::format($reputation->lastEventAt) : null,
            $reputation->totalEvents,
        ]);
    }

    /** @param array<string, mixed> $row */
    private function fromRow(array $row): PeerReputation
    {
        return new PeerReputation(
            peerId: (string) $row['peer_id'],
            score: (float) $row['score'],
            consecutiveExclusions: (int) $row['consecutive_exclusions'],
            excludedUntil: $row['excluded_until'] !== null ? PdoTimestamp::parse((string) $row['excluded_until']) : null,
            lastEventAt: $row['last_event_at'] !== null ? PdoTimestamp::parse((string) $row['last_event_at']) : null,
            totalEvents: (int) $row['total_events'],
        );
    }
}

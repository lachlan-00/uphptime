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

use DateTimeImmutable;
use DateTimeZone;
use PDO;
use Uphptime\Check\CheckOutcome;
use Uphptime\History\HistoryRecord;
use Uphptime\History\HistoryRecordRole;
use Uphptime\Site\CheckType;
use Uphptime\Site\SiteState;
use Uphptime\Storage\HistoryRepositoryInterface;

/**
 * One table across every site/day, indexed on (site_id, ts) - a pure-append write plus range reads,
 * no transaction needed. evidence is the one genuinely variable-shape field and gets a JSON column.
 */
final class PdoHistoryRepository implements HistoryRepositoryInterface
{
    use PdoAccess;

    public function __construct(
        private readonly PDO $pdo,
        private readonly SqlDialect $dialect,
        private readonly string $timezone = 'UTC',
    ) {}

    public function append(string $siteId, HistoryRecord $record): void
    {
        $this->execute(
            'INSERT INTO history (site_id, ts, check_type, source, outcome, http_status, response_time_ms, '
            . 'keyword_matched, error, state_before, state_after, excluded_by_maintenance, evidence, '
            . 'attestation, role, flagged, peer_id, peer_label) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)',
            [
                $siteId,
                PdoTimestamp::format($record->ts),
                $record->checkType->value,
                $record->source,
                $record->outcome->value,
                $record->httpStatus,
                $record->responseTimeMs,
                $record->keywordMatched === null ? null : ($record->keywordMatched ? 1 : 0),
                $record->error,
                $record->stateBefore->value,
                $record->stateAfter->value,
                $record->excludedByMaintenance ? 1 : 0,
                $record->evidence !== null ? json_encode($record->evidence, JSON_THROW_ON_ERROR) : null,
                $record->attestation,
                $record->role->value,
                $record->flagged ? 1 : 0,
                $record->peerId,
                $record->peerLabel,
            ],
        );
    }

    public function datesWithHistory(string $siteId): array
    {
        $rows = $this->query('SELECT DISTINCT DATE(ts) AS history_date FROM history WHERE site_id = ? ORDER BY history_date ASC', [$siteId]);

        return array_map(static fn(array $row): string => (string) $row['history_date'], $rows);
    }

    public function deleteDay(string $siteId, string $date): void
    {
        [$start, $end] = $this->dayBounds($date);

        $this->execute('DELETE FROM history WHERE site_id = ? AND ts >= ? AND ts < ?', [$siteId, $start, $end]);
    }

    public function readDay(string $siteId, string $date): array
    {
        [$start, $end] = $this->dayBounds($date);

        return array_map(
            fn(array $row): HistoryRecord => $this->fromRow($row),
            $this->query('SELECT * FROM history WHERE site_id = ? AND ts >= ? AND ts < ? ORDER BY ts ASC', [$siteId, $start, $end]),
        );
    }

    /** @return array{0: string, 1: string} */
    private function dayBounds(string $date): array
    {
        $tz    = new DateTimeZone($this->timezone);
        $start = new DateTimeImmutable($date . 'T00:00:00', $tz);

        return [PdoTimestamp::format($start), PdoTimestamp::format($start->modify('+1 day'))];
    }

    /** @param array<string, mixed> $row */
    private function fromRow(array $row): HistoryRecord
    {
        $evidence = $row['evidence'] !== null ? json_decode((string) $row['evidence'], true, 512, JSON_THROW_ON_ERROR) : null;

        return new HistoryRecord(
            ts: PdoTimestamp::parse((string) $row['ts']),
            siteId: (string) $row['site_id'],
            checkType: CheckType::from((string) $row['check_type']),
            source: (string) $row['source'],
            outcome: CheckOutcome::from((string) $row['outcome']),
            httpStatus: $row['http_status'] !== null ? (int) $row['http_status'] : null,
            responseTimeMs: $row['response_time_ms'] !== null ? (int) $row['response_time_ms'] : null,
            keywordMatched: $row['keyword_matched'] !== null ? $this->dialect->decodeBool($row['keyword_matched']) : null,
            error: $row['error'],
            stateBefore: SiteState::from((string) $row['state_before']),
            stateAfter: SiteState::from((string) $row['state_after']),
            excludedByMaintenance: $this->dialect->decodeBool($row['excluded_by_maintenance']),
            evidence: is_array($evidence) ? $evidence : null,
            attestation: (string) $row['attestation'],
            role: HistoryRecordRole::from((string) $row['role']),
            flagged: $this->dialect->decodeBool($row['flagged']),
            peerId: $row['peer_id'] !== null ? (string) $row['peer_id'] : null,
            peerLabel: $row['peer_label'] !== null ? (string) $row['peer_label'] : null,
        );
    }
}

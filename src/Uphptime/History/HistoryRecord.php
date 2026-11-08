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

namespace Uphptime\History;

use DateTimeImmutable;
use DateTimeInterface;
use Uphptime\Check\CheckOutcome;
use Uphptime\Site\CheckType;
use Uphptime\Site\SiteState;

/**
 * One JSONL line: a single check result, with the state transition it caused written alongside it.
 * `source` is "self" or "peer:<key_id>"; `evidence`/`attestation`/`role`/`flagged` all default to
 * null/"none"/Primary/false so an older-shaped line without them still parses unchanged.
 */
final class HistoryRecord
{
    /** @param array<string, mixed>|null $evidence */
    public function __construct(
        public readonly DateTimeImmutable $ts,
        public readonly string $siteId,
        public readonly CheckType $checkType,
        public readonly string $source,
        public readonly CheckOutcome $outcome,
        public readonly ?int $httpStatus,
        public readonly ?int $responseTimeMs,
        public readonly ?bool $keywordMatched,
        public readonly ?string $error,
        public readonly SiteState $stateBefore,
        public readonly SiteState $stateAfter,
        public readonly bool $excludedByMaintenance,
        public readonly ?array $evidence = null,
        public readonly string $attestation = 'none',
        public readonly HistoryRecordRole $role = HistoryRecordRole::Primary,
        public readonly bool $flagged = false,
        public readonly ?string $peerId = null,
        public readonly ?string $peerLabel = null,
    ) {}

    /** @param array<string, mixed> $data */
    public static function fromArray(array $data): self
    {
        $evidence = $data['evidence'] ?? null;

        return new self(
            ts: new DateTimeImmutable((string) $data['ts']),
            siteId: (string) $data['site_id'],
            checkType: CheckType::from((string) $data['check_type']),
            source: (string) $data['source'],
            outcome: CheckOutcome::from((string) $data['outcome']),
            httpStatus: $data['http_status'],
            responseTimeMs: $data['response_time_ms'],
            keywordMatched: $data['keyword_matched'],
            error: $data['error'],
            stateBefore: SiteState::from((string) $data['state_before']),
            stateAfter: SiteState::from((string) $data['state_after']),
            excludedByMaintenance: (bool) $data['excluded_by_maintenance'],
            evidence: is_array($evidence) ? $evidence : null,
            attestation: (string) ($data['attestation'] ?? 'none'),
            role: HistoryRecordRole::tryFrom((string) ($data['role'] ?? '')) ?? HistoryRecordRole::Primary,
            flagged: (bool) ($data['flagged'] ?? false),
            peerId: isset($data['peer_id']) ? (string) $data['peer_id'] : null,
            peerLabel: isset($data['peer_label']) ? (string) $data['peer_label'] : null,
        );
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'ts' => $this->ts->format(DateTimeInterface::ATOM),
            'site_id' => $this->siteId,
            'check_type' => $this->checkType->value,
            'source' => $this->source,
            'peer_id' => $this->peerId,
            'peer_label' => $this->peerLabel,
            'outcome' => $this->outcome->value,
            'http_status' => $this->httpStatus,
            'response_time_ms' => $this->responseTimeMs,
            'keyword_matched' => $this->keywordMatched,
            'error' => $this->error,
            'state_before' => $this->stateBefore->value,
            'state_after' => $this->stateAfter->value,
            'excluded_by_maintenance' => $this->excludedByMaintenance,
            'evidence' => $this->evidence,
            'attestation' => $this->attestation,
            'role' => $this->role->value,
            'flagged' => $this->flagged,
        ];
    }
}

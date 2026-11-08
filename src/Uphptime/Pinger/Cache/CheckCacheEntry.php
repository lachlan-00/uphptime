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

namespace Uphptime\Pinger\Cache;

use DateTimeImmutable;
use DateTimeInterface;
use Uphptime\Check\CheckOutcome;
use Uphptime\Check\CheckResult;
use Uphptime\Pinger\DelegatedCheckOutcome;
use Uphptime\Site\CheckType;
use Uphptime\Site\KeywordMode;
use Uphptime\Site\Site;
use Uphptime\Site\SiteRuntimeState;
use Uphptime\Site\SiteState;

/**
 * One tracked target's most recent probe result, stored one file per fingerprint. Never written for
 * an Error outcome - see CacheRefresher::transient().
 */
final class CheckCacheEntry
{
    /** @param array<string, mixed>|null $evidence */
    public function __construct(
        public readonly string $fingerprint,
        public readonly CheckType $checkType,
        public readonly ?string $url,
        public readonly ?string $host,
        public readonly ?int $port,
        public readonly ?string $keyword,
        public readonly ?KeywordMode $keywordMode,
        public readonly CheckResult $result,
        public readonly ?array $evidence,
        public readonly string $attestation,
        public readonly DateTimeImmutable $probedAt,
        public readonly DateTimeImmutable $expiresAt,
        public readonly DateTimeImmutable $lastRequestedAt,
    ) {}

    /** @param array<string, mixed> $data */
    public static function fromArray(array $data): self
    {
        $target      = is_array($data['target'] ?? null) ? $data['target'] : [];
        $result      = is_array($data['result'] ?? null) ? $data['result'] : [];
        $evidence    = $data['evidence'] ?? null;
        $keywordMode = isset($target['keyword_mode']) ? KeywordMode::tryFrom((string) $target['keyword_mode']) : null;

        return new self(
            fingerprint: (string) $data['fingerprint'],
            checkType: CheckType::from((string) $data['check_type']),
            url: $target['url'] ?? null,
            host: $target['host'] ?? null,
            port: $target['port'] ?? null,
            keyword: $target['keyword'] ?? null,
            keywordMode: $keywordMode,
            result: new CheckResult(
                CheckOutcome::from((string) $result['outcome']),
                $result['http_status'] ?? null,
                $result['response_time_ms'] ?? null,
                $result['keyword_matched'] ?? null,
                $result['error'] ?? null,
            ),
            evidence: is_array($evidence) ? $evidence : null,
            attestation: (string) ($data['attestation'] ?? 'none'),
            probedAt: new DateTimeImmutable((string) $data['probed_at']),
            expiresAt: new DateTimeImmutable((string) $data['expires_at']),
            lastRequestedAt: new DateTimeImmutable((string) $data['last_requested_at']),
        );
    }

    // A one-off result that was just probed but must never be persisted (see CacheRefresher).
    public static function transient(string $fingerprint, Site $site, DelegatedCheckOutcome $outcome, DateTimeImmutable $now): self
    {
        return new self(
            fingerprint: $fingerprint,
            checkType: $site->checkType,
            url: $site->url,
            host: $site->host,
            port: $site->port,
            keyword: $site->keyword,
            keywordMode: $site->keywordMode,
            result: $outcome->result,
            evidence: $outcome->evidence,
            attestation: 'none',
            probedAt: $now,
            expiresAt: $now,
            lastRequestedAt: $now,
        );
    }

    public function ageSeconds(DateTimeImmutable $now): int
    {
        return max(0, $now->getTimestamp() - $this->probedAt->getTimestamp());
    }

    public function isExpired(DateTimeImmutable $now): bool
    {
        return $now >= $this->expiresAt;
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'fingerprint' => $this->fingerprint,
            'check_type' => $this->checkType->value,
            'target' => [
                'url' => $this->url,
                'host' => $this->host,
                'port' => $this->port,
                'keyword' => $this->keyword,
                'keyword_mode' => $this->keywordMode?->value,
            ],
            'result' => [
                'outcome' => $this->result->outcome->value,
                'http_status' => $this->result->httpStatus,
                'response_time_ms' => $this->result->responseTimeMs,
                'keyword_matched' => $this->result->keywordMatched,
                'error' => $this->result->error,
            ],
            'evidence' => $this->evidence,
            'attestation' => $this->attestation,
            'probed_at' => $this->probedAt->format(DateTimeInterface::ATOM),
            'expires_at' => $this->expiresAt->format(DateTimeInterface::ATOM),
            'last_requested_at' => $this->lastRequestedAt->format(DateTimeInterface::ATOM),
        ];
    }

    // Reconstructs the ephemeral site DTO this entry was probed for, so the refresh cron can re-probe
    // it without ever having seen the original request payload.
    public function toSite(): Site
    {
        return new Site(
            id: 'cached',
            name: 'cached',
            url: $this->url,
            host: $this->host,
            port: $this->port,
            checkType: $this->checkType,
            keyword: $this->keyword,
            keywordMode: $this->keywordMode,
            checkIntervalSeconds: null,
            enabled: true,
            createdAt: $this->probedAt,
            state: new SiteRuntimeState(SiteState::Unknown, 0, null, null, $this->probedAt, null),
        );
    }

    // Reactive hits bump this to the requesting moment; proactive cron refreshes pass the same value
    // back in unchanged, so idle eviction tracks genuine requests, not the cron's own activity.
    public function withLastRequestedAt(DateTimeImmutable $lastRequestedAt): self
    {
        return new self(
            $this->fingerprint,
            $this->checkType,
            $this->url,
            $this->host,
            $this->port,
            $this->keyword,
            $this->keywordMode,
            $this->result,
            $this->evidence,
            $this->attestation,
            $this->probedAt,
            $this->expiresAt,
            $lastRequestedAt,
        );
    }
}

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

namespace Uphptime\Pinger\Http;

use DateTimeImmutable;
use DateTimeInterface;

/**
 * Result attestation Layer 2 evidence for one HTTP(S) probe; free to collect since curl is already
 * making the connection and reading headers.
 */
final class HttpProbeEvidence
{
    /** @param array{dns: ?int, connect: ?int, tls: ?int, first_byte: ?int, total: ?int} $timingMs */
    public function __construct(
        public readonly string $resolvedIp,
        public readonly ?string $tlsFingerprintSha256,
        public readonly ?DateTimeImmutable $tlsExpiry,
        public readonly ?string $responseDateHeader,
        public readonly string $headersHash,
        public readonly string $bodyHash,
        public readonly array $timingMs,
        public readonly DateTimeImmutable $probeStartedAt,
        public readonly DateTimeImmutable $probeEndedAt,
        public readonly ?string $challengeTokenObserved = null,
    ) {}

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'resolved_ip' => $this->resolvedIp,
            'tls_fingerprint_sha256' => $this->tlsFingerprintSha256,
            'tls_expiry' => $this->tlsExpiry?->format(DateTimeInterface::ATOM),
            'response_date_header' => $this->responseDateHeader,
            'headers_hash' => 'sha256:' . $this->headersHash,
            'body_hash' => 'sha256:' . $this->bodyHash,
            'timing_ms' => $this->timingMs,
            'probe_started_at' => $this->probeStartedAt->format(DateTimeInterface::ATOM),
            'probe_ended_at' => $this->probeEndedAt->format(DateTimeInterface::ATOM),
            'challenge_token_observed' => $this->challengeTokenObserved,
        ];
    }
}

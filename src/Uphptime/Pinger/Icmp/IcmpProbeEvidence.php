<?php

declare(strict_types=1);

/**
 * vim:set softtabstop=4 shiftwidth=4 expandtab:
 *
 * LICENSE: GNU Affero General Public License, version 3 (AGPL-3.0-or-later)
 * Copyright uPHPtime contributors, 2026
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

namespace Uphptime\Pinger\Icmp;

use DateTimeImmutable;
use DateTimeInterface;

/**
 * The thin ICMP subset of result attestation evidence: an echo reply has no headers, body or TLS
 * certificate, so only the resolved IP, round-trip time and probe window are meaningful.
 */
final class IcmpProbeEvidence
{
    public function __construct(
        public readonly string $resolvedIp,
        public readonly int $rttMs,
        public readonly DateTimeImmutable $probeStartedAt,
        public readonly DateTimeImmutable $probeEndedAt,
    ) {}

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'resolved_ip' => $this->resolvedIp,
            'tls_fingerprint_sha256' => null,
            'tls_expiry' => null,
            'response_date_header' => null,
            'headers_hash' => null,
            'body_hash' => null,
            'timing_ms' => ['dns' => null, 'connect' => null, 'tls' => null, 'first_byte' => null, 'total' => $this->rttMs],
            'probe_started_at' => $this->probeStartedAt->format(DateTimeInterface::ATOM),
            'probe_ended_at' => $this->probeEndedAt->format(DateTimeInterface::ATOM),
            'challenge_token_observed' => null,
        ];
    }
}

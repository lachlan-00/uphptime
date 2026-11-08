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

namespace Uphptime\Pinger\Tcp;

use DateTimeImmutable;
use DateTimeInterface;

/**
 * The thin TCP subset of result attestation evidence: a raw connect has no headers, body or TLS
 * certificate, so only the resolved IP, connect timing and probe window are meaningful.
 */
final class TcpProbeEvidence
{
    public function __construct(
        public readonly string $resolvedIp,
        public readonly int $connectMs,
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
            'timing_ms' => ['dns' => null, 'connect' => $this->connectMs, 'tls' => null, 'first_byte' => null, 'total' => $this->connectMs],
            'probe_started_at' => $this->probeStartedAt->format(DateTimeInterface::ATOM),
            'probe_ended_at' => $this->probeEndedAt->format(DateTimeInterface::ATOM),
            'challenge_token_observed' => null,
        ];
    }
}

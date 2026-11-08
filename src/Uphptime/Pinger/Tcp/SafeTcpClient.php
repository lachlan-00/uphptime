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
use RuntimeException;
use Uphptime\Check\Tcp\TcpClientInterface;
use Uphptime\Pinger\DnsResolverInterface;
use Uphptime\Pinger\EgressPolicyException;
use Uphptime\Pinger\EgressPolicyInterface;
use Uphptime\Pinger\PreferredIpSelector;

/**
 * Implements TcpClientInterface exactly like StreamTcpClient, but resolves the host once, validates
 * the IP and port against the egress policy, then connects to the validated IP directly rather than
 * the hostname, so a second DNS lookup at connect time can't substitute a different address.
 */
final class SafeTcpClient implements TcpClientInterface
{
    private ?TcpProbeEvidence $lastEvidence = null;

    public function __construct(
        private readonly DnsResolverInterface $dnsResolver,
        private readonly EgressPolicyInterface $egressPolicy,
        private readonly PreferredIpSelector $preferredIpSelector,
    ) {}

    public function connect(string $host, int $port, float $timeoutSeconds): void
    {
        $this->lastEvidence = null;

        if (!$this->egressPolicy->isPortAllowed($port)) {
            throw new EgressPolicyException(sprintf('Port is not in the TCP allowlist: %d', $port));
        }

        $ips = $this->dnsResolver->resolve($host);
        if ($ips === []) {
            throw new RuntimeException(sprintf('Unable to resolve host: %s', $host));
        }

        $ip = $this->preferredIpSelector->choose($ips);
        if ($this->egressPolicy->isIpBlocked($ip)) {
            throw new EgressPolicyException(sprintf('Target IP is blocked by the egress policy: %s', $ip));
        }

        if (!$this->egressPolicy->isTargetAllowed($host, $ip)) {
            throw new EgressPolicyException(sprintf('Target is not in the calling peer\'s allowlist: %s', $host));
        }

        $probeStartedAt = new DateTimeImmutable();
        $errorNumber    = 0;
        $errorMessage   = '';
        $address        = str_contains($ip, ':') ? sprintf('tcp://[%s]:%d', $ip, $port) : sprintf('tcp://%s:%d', $ip, $port);
        $start          = microtime(true);
        $stream         = @stream_socket_client($address, $errorNumber, $errorMessage, $timeoutSeconds);
        $connectMs      = (int) round((microtime(true) - $start) * 1000);

        if ($stream === false) {
            throw new RuntimeException($errorMessage !== null && $errorMessage !== '' ? $errorMessage : 'Unable to connect.');
        }

        fclose($stream);

        $this->lastEvidence = new TcpProbeEvidence($ip, $connectMs, $probeStartedAt, new DateTimeImmutable());
    }

    public function lastEvidence(): ?TcpProbeEvidence
    {
        return $this->lastEvidence;
    }
}

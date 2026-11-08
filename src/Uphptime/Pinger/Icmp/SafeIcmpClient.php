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
use RuntimeException;
use Uphptime\Check\Icmp\IcmpClientInterface;
use Uphptime\Check\Icmp\SystemPingClient;
use Uphptime\Pinger\DnsResolverInterface;
use Uphptime\Pinger\EgressPolicyException;
use Uphptime\Pinger\EgressPolicyInterface;
use Uphptime\Pinger\PreferredIpSelector;

/**
 * Implements IcmpClientInterface exactly like SystemPingClient, but resolves the host once, validates
 * the IP against the egress policy (no port check - ICMP has none), then pings the validated IP
 * directly rather than the hostname, so a second DNS lookup at ping time can't substitute a different
 * address.
 */
final class SafeIcmpClient implements IcmpClientInterface
{
    private ?IcmpProbeEvidence $lastEvidence = null;

    public function __construct(
        private readonly DnsResolverInterface $dnsResolver,
        private readonly EgressPolicyInterface $egressPolicy,
        private readonly SystemPingClient $systemPingClient,
        private readonly PreferredIpSelector $preferredIpSelector,
    ) {}

    public function lastEvidence(): ?IcmpProbeEvidence
    {
        return $this->lastEvidence;
    }

    public function ping(string $host, float $timeoutSeconds): int
    {
        $this->lastEvidence = null;

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
        $rttMs          = $this->systemPingClient->ping($ip, $timeoutSeconds);

        $this->lastEvidence = new IcmpProbeEvidence($ip, $rttMs, $probeStartedAt, new DateTimeImmutable());

        return $rttMs;
    }
}

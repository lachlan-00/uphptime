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

namespace Uphptime\Pinger;

use Uphptime\Check\CheckConfigurationException;
use Uphptime\Check\CheckResult;
use Uphptime\Check\Http\HttpChecker;
use Uphptime\Check\Http\HttpKeywordChecker;
use Uphptime\Check\Icmp\IcmpChecker;
use Uphptime\Check\Icmp\SystemPingClient;
use Uphptime\Check\Tcp\TcpChecker;
use Uphptime\Peer\Peer;
use Uphptime\Pinger\Http\SafeHttpClient;
use Uphptime\Pinger\Icmp\SafeIcmpClient;
use Uphptime\Pinger\Tcp\SafeTcpClient;
use Uphptime\Site\CheckType;
use Uphptime\Site\Site;

/**
 * Runs a delegated check for an ephemeral, never-persisted site DTO, reusing the real milestone-1
 * checker classes verbatim, only wiring them to the egress-policy-enforcing Safe* clients instead of
 * CurlHttpClient/StreamTcpClient. Never touches a repository: a pinger stays stateless about sites.
 */
final class DelegatedCheckExecutor implements DelegatedCheckExecutorInterface
{
    /**
     * @param int[] $tcpPortAllowlist
     * @param string[] $egressAllowedRanges
     */
    public function __construct(
        private readonly DnsResolverInterface $dnsResolver,
        private readonly int $maxRedirects,
        private readonly int $responseSizeCapBytes,
        private readonly string $httpUserAgent,
        private readonly array $tcpPortAllowlist,
        private readonly array $egressAllowedRanges,
        private readonly PreferredIpSelector $preferredIpSelector,
    ) {}

    public function execute(Site $site, ?Peer $callingPeer, float $timeoutSeconds): DelegatedCheckOutcome
    {
        $egressPolicy = new DefaultEgressPolicy(
            BlockedRanges::defaults(),
            $this->egressAllowedRanges,
            $this->tcpPortAllowlist,
            $callingPeer !== null ? $callingPeer->targetAllowlist : [],
        );

        $httpClient   = new SafeHttpClient($this->dnsResolver, $egressPolicy, $this->maxRedirects, $this->responseSizeCapBytes, $this->preferredIpSelector);
        $tcpClient    = new SafeTcpClient($this->dnsResolver, $egressPolicy, $this->preferredIpSelector);
        $icmpClient   = new SafeIcmpClient($this->dnsResolver, $egressPolicy, new SystemPingClient(), $this->preferredIpSelector);

        $checker = match ($site->checkType) {
            CheckType::Http => new HttpChecker($httpClient, $this->httpUserAgent),
            CheckType::HttpKeyword => new HttpKeywordChecker($httpClient, $this->httpUserAgent),
            CheckType::Tcp => new TcpChecker($tcpClient),
            CheckType::Icmp => new IcmpChecker($icmpClient),
        };

        try {
            $result = $checker->check($site, $timeoutSeconds);
        } catch (EgressPolicyException|CheckConfigurationException $exception) {
            return new DelegatedCheckOutcome(CheckResult::error($exception->getMessage()), null);
        }

        $evidence = $httpClient->lastEvidence()?->toArray() ?? $tcpClient->lastEvidence()?->toArray() ?? $icmpClient->lastEvidence()?->toArray();

        return new DelegatedCheckOutcome($result, $evidence);
    }
}

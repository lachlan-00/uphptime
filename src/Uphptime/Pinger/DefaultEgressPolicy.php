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

use InvalidArgumentException;

/**
 * An explicitly-allowed range always wins over the block list. A port allowlist always applies to
 * TCP checks; a non-empty per-peer target allowlist additionally restricts which hosts/IPs that
 * specific peer may ask this node to probe (empty means allow-all, the milestone-1-era default).
 */
final class DefaultEgressPolicy implements EgressPolicyInterface
{
    /**
     * @param IpRange[] $blockedRanges
     * @param string[] $allowedRanges
     * @param int[] $tcpPortAllowlist
     * @param string[] $targetAllowlist
     */
    public function __construct(
        private readonly array $blockedRanges,
        private readonly array $allowedRanges,
        private readonly array $tcpPortAllowlist,
        private readonly array $targetAllowlist,
    ) {}

    public function isIpBlocked(string $ip): bool
    {
        foreach ($this->allowedRanges as $cidr) {
            if ($this->tryRange($cidr)?->contains($ip) === true) {
                return false;
            }
        }

        foreach ($this->blockedRanges as $range) {
            if ($range->contains($ip)) {
                return true;
            }
        }

        $embeddedIpv4 = $this->embeddedIpv4($ip);

        return $embeddedIpv4 !== null && $this->isIpBlocked($embeddedIpv4);
    }

    public function isPortAllowed(int $port): bool
    {
        return in_array($port, $this->tcpPortAllowlist, true);
    }

    public function isTargetAllowed(string $host, string $resolvedIp): bool
    {
        if ($this->targetAllowlist === []) {
            return true;
        }

        foreach ($this->targetAllowlist as $pattern) {
            if ($this->matchesPattern($pattern, $host, $resolvedIp)) {
                return true;
            }
        }

        return false;
    }

    // ::ffff:a.b.c.d maps to IPv4 a.b.c.d; re-checking it closes off using the mapped form to dodge the v4 list.
    private function embeddedIpv4(string $ip): ?string
    {
        if (!str_starts_with($ip, '::ffff:')) {
            return null;
        }

        $candidate = substr($ip, 7);

        return filter_var($candidate, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) !== false ? $candidate : null;
    }

    private function matchesPattern(string $pattern, string $host, string $resolvedIp): bool
    {
        $range = $this->tryRange($pattern);
        if ($range !== null) {
            return $range->contains($resolvedIp);
        }

        return $host === $pattern || str_ends_with($host, '.' . $pattern);
    }

    private function tryRange(string $cidr): ?IpRange
    {
        if (!str_contains($cidr, '/')) {
            return null;
        }

        try {
            return new IpRange($cidr);
        } catch (InvalidArgumentException) {
            return null;
        }
    }
}

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
 * A CIDR range over packed inet_pton() bytes and a bitmask compare; works for IPv4 and IPv6 alike.
 */
final class IpRange
{
    private readonly string $networkBytes;
    private readonly int $prefixLength;

    public function __construct(string $cidr)
    {
        $parts = explode('/', $cidr, 2);
        if (count($parts) !== 2) {
            throw new InvalidArgumentException(sprintf('Not a CIDR range: %s', $cidr));
        }

        $packed = inet_pton($parts[0]);
        if ($packed === false) {
            throw new InvalidArgumentException(sprintf('Not a valid network address: %s', $cidr));
        }

        $this->networkBytes = $packed;
        $this->prefixLength = (int) $parts[1];
    }

    public function contains(string $ip): bool
    {
        $packedIp = inet_pton($ip);
        if ($packedIp === false || strlen($packedIp) !== strlen($this->networkBytes)) {
            return false;
        }

        $fullBytes     = intdiv($this->prefixLength, 8);
        $remainingBits = $this->prefixLength % 8;

        if ($fullBytes > 0 && strncmp($packedIp, $this->networkBytes, $fullBytes) !== 0) {
            return false;
        }

        if ($remainingBits === 0) {
            return true;
        }

        $mask = (0xff << (8 - $remainingBits)) & 0xff;

        return (ord($packedIp[$fullBytes]) & $mask) === (ord($this->networkBytes[$fullBytes]) & $mask);
    }
}

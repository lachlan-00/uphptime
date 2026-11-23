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

namespace Uphptime\Support;

/**
 * Reads /proc/net/if_inet6 (Linux-only) rather than probing an address, since only an interface check is reliable on an isolated bridge network.
 * Unreadable/missing/non-Linux fail safe to false, so a missing read just means preferring IPv4 when available.
 */
final class SystemNetworkStack implements NetworkStackInterface
{
    public function __construct(private readonly string $inet6FilePath = '/proc/net/if_inet6') {}

    public function hasIpv6Route(): bool
    {
        $contents = @file_get_contents($this->inet6FilePath);
        if ($contents === false || trim($contents) === '') {
            return false;
        }

        foreach (explode("\n", trim($contents)) as $line) {
            $fields = preg_split('/\s+/', trim($line));
            if ($fields === false || count($fields) < 6) {
                continue;
            }

            // Fields are: address, netlink device number, prefix length, scope, flags, device name.
            [, , , $scope, , $device] = $fields;

            // Scope 0x00 is global; loopback/link-local/site-local addresses can't reach the internet.
            if ($device !== 'lo' && strcasecmp($scope, '00') === 0) {
                return true;
            }
        }

        return false;
    }
}

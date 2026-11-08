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

/**
 * The default-blocked ranges a pinger refuses to probe unless explicitly allowed in node config:
 * loopback, link-local (incl. cloud metadata), RFC1918, CGNAT, multicast, reserved/documentation,
 * and their IPv6 equivalents.
 */
final class BlockedRanges
{
    /** @return IpRange[] */
    public static function defaults(): array
    {
        $cidrs = [
            '127.0.0.0/8',
            '169.254.0.0/16',
            '10.0.0.0/8',
            '172.16.0.0/12',
            '192.168.0.0/16',
            '100.64.0.0/10',
            '224.0.0.0/4',
            '0.0.0.0/8',
            '192.0.0.0/24',
            '192.0.2.0/24',
            '198.18.0.0/15',
            '198.51.100.0/24',
            '203.0.113.0/24',
            '240.0.0.0/4',
            '255.255.255.255/32',
            '::1/128',
            '::/128',
            'fe80::/10',
            'fc00::/7',
            'ff00::/8',
            '::ffff:0:0/96',
            '2001:db8::/32',
        ];

        return array_map(static fn(string $cidr): IpRange => new IpRange($cidr), $cidrs);
    }
}

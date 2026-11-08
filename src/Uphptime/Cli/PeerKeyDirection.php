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

namespace Uphptime\Cli;

/**
 * The --key-direction= values a CLI command accepts, mapped to a PeerKey's outbound/inbound flags.
 */
enum PeerKeyDirection: string
{
    case Both     = 'both';
    case Inbound  = 'inbound';
    case Outbound = 'outbound';

    /** @return array{0: bool, 1: bool} outbound then inbound */
    public function flags(): array
    {
        return match ($this) {
            self::Both => [true, true],
            self::Inbound => [false, true],
            self::Outbound => [true, false],
        };
    }
}

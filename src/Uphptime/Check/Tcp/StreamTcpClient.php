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

namespace Uphptime\Check\Tcp;

use RuntimeException;

/**
 * Thin stream_socket_client() wrapper for a raw TCP connect check.
 */
final class StreamTcpClient implements TcpClientInterface
{
    public function connect(string $host, int $port, float $timeoutSeconds): void
    {
        $errorNumber  = 0;
        $errorMessage = '';
        $stream       = @stream_socket_client(
            sprintf('tcp://%s:%d', $host, $port),
            $errorNumber,
            $errorMessage,
            $timeoutSeconds,
        );

        if ($stream === false) {
            throw new RuntimeException($errorMessage !== null && $errorMessage !== '' ? $errorMessage : 'Unable to connect.');
        }

        fclose($stream);
    }
}

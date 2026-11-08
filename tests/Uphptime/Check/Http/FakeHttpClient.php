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

namespace Uphptime\Tests\Check\Http;

use RuntimeException;
use Uphptime\Check\Http\HttpClientInterface;
use Uphptime\Check\Http\HttpResponse;
use Uphptime\Site\ChallengeDelivery;

/**
 * A scripted HttpClientInterface double; never touches a real socket.
 */
final class FakeHttpClient implements HttpClientInterface
{
    private ?string $failureMessage = null;
    private ?HttpResponse $response = null;

    public static function failingWith(string $message): self
    {
        $client                 = new self();
        $client->failureMessage = $message;

        return $client;
    }

    public static function returning(HttpResponse $response): self
    {
        $client           = new self();
        $client->response = $response;

        return $client;
    }

    public function get(string $url, float $timeoutSeconds, string $userAgent, ?ChallengeDelivery $challengeDelivery = null): HttpResponse
    {
        if ($this->failureMessage !== null) {
            throw new RuntimeException($this->failureMessage);
        }

        return $this->response ?? throw new RuntimeException('FakeHttpClient has no response configured.');
    }
}

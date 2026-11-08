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

use InvalidArgumentException;
use Uphptime\Peer\PeerKey;
use Uphptime\Support\ClockInterface;

/**
 * Turns --key-id=/--secret=/--key-direction= options into a PeerKey, generating a fresh key+secret
 * pair to relay out-of-band when neither is given, or decoding an already-known secret when both are.
 */
final class PeerKeyOptionResolver
{
    public function __construct(private readonly ClockInterface $clock) {}

    /**
     * @param array<string, string> $options
     * @return array{key: PeerKey, generatedSecretBase64: ?string}
     */
    public function resolve(array $options): array
    {
        $direction            = PeerKeyDirection::from($options['key-direction'] ?? 'both');
        [$outbound, $inbound] = $direction->flags();

        $hasKeyId  = isset($options['key-id']);
        $hasSecret = isset($options['secret']);
        if ($hasKeyId !== $hasSecret) {
            throw new InvalidArgumentException('--key-id and --secret must be given together.');
        }

        $now = $this->clock->now();

        if ($hasKeyId) {
            $secret = base64_decode($options['secret'], true);
            if ($secret === false) {
                throw new InvalidArgumentException('--secret must be valid base64.');
            }

            $key = new PeerKey($options['key-id'], $secret, $outbound, $inbound, true, $now);

            return ['key' => $key, 'generatedSecretBase64' => null];
        }

        $secret = random_bytes(32);
        $key    = new PeerKey(bin2hex(random_bytes(8)), $secret, $outbound, $inbound, true, $now);

        return ['key' => $key, 'generatedSecretBase64' => base64_encode($secret)];
    }
}

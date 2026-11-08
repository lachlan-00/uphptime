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

namespace Uphptime\Api\Security;

use DateTimeImmutable;
use Uphptime\Support\AtomicWriter;

/**
 * Fixed 60-second window keyed by floor(epoch/60), one file per key ID under
 * data/peer_runtime/rate_limits/<key_id>.json.
 */
final class JsonRateLimiter implements RateLimiterInterface
{
    public function __construct(
        private readonly AtomicWriter $writer,
        private readonly string $baseDirectory,
    ) {}

    public function allow(string $keyId, DateTimeImmutable $now, int $limitPerMinute): bool
    {
        $path    = $this->pathFor($keyId);
        $minute  = intdiv($now->getTimestamp(), 60);
        $allowed = false;

        $this->writer->readModifyWrite($path, function (?string $current) use ($minute, $limitPerMinute, &$allowed): string {
            [$windowStartMinute, $count] = $this->decodeState($current);

            if ($windowStartMinute !== $minute) {
                $windowStartMinute = $minute;
                $count             = 0;
            }

            $allowed = $count < $limitPerMinute;
            if ($allowed) {
                $count++;
            }

            return json_encode(['window_start_minute' => $windowStartMinute, 'count' => $count], JSON_THROW_ON_ERROR) . "\n";
        });

        return $allowed;
    }

    /** @return array{0: int, 1: int} */
    private function decodeState(?string $contents): array
    {
        if ($contents === null) {
            return [0, 0];
        }

        $decoded = json_decode($contents, true);
        if (!is_array($decoded)) {
            return [0, 0];
        }

        return [(int) ($decoded['window_start_minute'] ?? 0), (int) ($decoded['count'] ?? 0)];
    }

    private function pathFor(string $keyId): string
    {
        $safeKeyId = preg_replace('/[^A-Za-z0-9_.-]/', '_', $keyId) ?? 'unknown';

        return $this->baseDirectory . DIRECTORY_SEPARATOR . $safeKeyId . '.json';
    }
}

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
use DateTimeInterface;
use Uphptime\Support\AtomicWriter;

/**
 * One file per inbound key ID, under data/peer_runtime/nonces/<key_id>.json. Every call prunes
 * expired entries under the same exclusive lock it checks/inserts with, so the file self-bounds
 * with no separate GC job and there is no prune-then-check race.
 */
final class JsonNonceStore implements NonceStoreInterface
{
    public function __construct(
        private readonly AtomicWriter $writer,
        private readonly string $baseDirectory,
    ) {}

    public function recordIfNew(string $keyId, string $nonce, DateTimeImmutable $now, int $windowSeconds): bool
    {
        $path  = $this->pathFor($keyId);
        $isNew = false;

        $this->writer->readModifyWrite($path, function (?string $current) use ($nonce, $now, $windowSeconds, &$isNew): string {
            $entries = $this->pruneExpired($this->decodeEntries($current), $now);

            if (array_key_exists($nonce, $entries)) {
                $isNew = false;
            } else {
                $isNew           = true;
                $expiresAt       = $now->modify(sprintf('+%d seconds', $windowSeconds));
                $entries[$nonce] = $expiresAt->format(DateTimeInterface::ATOM);
            }

            return json_encode(['entries' => $entries], JSON_THROW_ON_ERROR) . "\n";
        });

        return $isNew;
    }

    /** @return array<string, string> */
    private function decodeEntries(?string $contents): array
    {
        if ($contents === null) {
            return [];
        }

        $decoded = json_decode($contents, true);
        $entries = is_array($decoded) ? ($decoded['entries'] ?? []) : [];

        return is_array($entries) ? $entries : [];
    }

    private function pathFor(string $keyId): string
    {
        $safeKeyId = preg_replace('/[^A-Za-z0-9_.-]/', '_', $keyId) ?? 'unknown';

        return $this->baseDirectory . DIRECTORY_SEPARATOR . $safeKeyId . '.json';
    }

    /**
     * @param array<string, string> $entries
     * @return array<string, string>
     */
    private function pruneExpired(array $entries, DateTimeImmutable $now): array
    {
        return array_filter(
            $entries,
            static fn(string $expiresAt): bool => new DateTimeImmutable($expiresAt) > $now,
        );
    }
}

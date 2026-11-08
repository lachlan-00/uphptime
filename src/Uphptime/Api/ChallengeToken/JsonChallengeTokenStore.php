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

namespace Uphptime\Api\ChallengeToken;

use DateTimeImmutable;
use DateTimeInterface;
use Uphptime\Support\AtomicWriter;

/**
 * One file per site ID, under data/challenge_tokens/<site-id>.json - JSON-only regardless of
 * storage_backend, exactly like the nonce store and rate limiter (equally short-lived, never history).
 */
final class JsonChallengeTokenStore implements ChallengeTokenStoreInterface
{
    public function __construct(
        private readonly AtomicWriter $writer,
        private readonly string $baseDirectory,
    ) {}

    public function current(string $siteId, DateTimeImmutable $now): ?ChallengeToken
    {
        $token = $this->decode($siteId, $this->writer->read($this->pathFor($siteId)));

        return $token !== null && $token->isValidAt($now) ? $token : null;
    }

    public function issue(string $siteId, DateTimeImmutable $now, int $ttlSeconds): ChallengeToken
    {
        $token = new ChallengeToken($siteId, bin2hex(random_bytes(16)), $now, $now->modify(sprintf('+%d seconds', $ttlSeconds)));

        $this->writer->write($this->pathFor($siteId), $this->encode($token));

        return $token;
    }

    private function decode(string $siteId, ?string $contents): ?ChallengeToken
    {
        if ($contents === null) {
            return null;
        }

        $decoded = json_decode($contents, true);
        if (!is_array($decoded) || !isset($decoded['token'], $decoded['issued_at'], $decoded['expires_at'])) {
            return null;
        }

        return new ChallengeToken(
            $siteId,
            (string) $decoded['token'],
            new DateTimeImmutable((string) $decoded['issued_at']),
            new DateTimeImmutable((string) $decoded['expires_at']),
        );
    }

    private function encode(ChallengeToken $token): string
    {
        return json_encode([
            'token' => $token->token,
            'issued_at' => $token->issuedAt->format(DateTimeInterface::ATOM),
            'expires_at' => $token->expiresAt->format(DateTimeInterface::ATOM),
        ], JSON_THROW_ON_ERROR) . "\n";
    }

    private function pathFor(string $siteId): string
    {
        $safeSiteId = preg_replace('/[^A-Za-z0-9_.-]/', '_', $siteId) ?? 'unknown';

        return $this->baseDirectory . DIRECTORY_SEPARATOR . $safeSiteId . '.json';
    }
}

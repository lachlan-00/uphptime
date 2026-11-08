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

namespace Uphptime\Api\Client;

use Uphptime\Api\Signing\HmacSignerInterface;
use Uphptime\Api\Signing\RequestCanonicalizer;
use Uphptime\Api\Signing\ResponseCanonicalizer;
use Uphptime\Api\Signing\SignatureHeaders;
use Uphptime\Check\CheckOutcome;
use Uphptime\Check\CheckResult;
use Uphptime\Peer\Peer;
use Uphptime\Site\Site;
use Uphptime\Support\ClockInterface;
use Uphptime\Support\Uuid;
use Uphptime\Version;

/**
 * Builds and signs the one outbound delegated-ping request this milestone defines, over curl, and
 * verifies the peer's signed response before trusting it.
 */
final class HttpPeerCheckClient implements PeerCheckClientInterface
{
    private const PATH = '/peer/v1/check-site';

    public function __construct(
        private readonly HmacSignerInterface $signer,
        private readonly RequestCanonicalizer $requestCanonicalizer,
        private readonly ResponseCanonicalizer $responseCanonicalizer,
        private readonly ClockInterface $clock,
    ) {}

    public function requestCheck(Peer $peer, Site $site, float $timeoutSeconds): DelegatedCheckResult
    {
        $key = $peer->outboundKey();
        if ($key === null || $peer->baseUrl === null) {
            throw new DelegatedCheckFailedException(sprintf('Peer "%s" has no active outbound key or base URL.', $peer->id));
        }

        $url       = rtrim($peer->baseUrl, '/') . self::PATH;
        $body      = (string) json_encode($this->payloadFor($site, $timeoutSeconds), JSON_THROW_ON_ERROR);
        $timestamp = (string) $this->clock->now()->getTimestamp();
        $nonce     = bin2hex(random_bytes(16));
        $requestId = Uuid::v4();

        $canonical = $this->requestCanonicalizer->canonicalize('POST', self::PATH, $key->keyId, $timestamp, $nonce, $requestId, $body);
        $signature = $this->signer->sign($canonical, $key->secret);

        $handle = curl_init($url);
        if ($handle === false) {
            throw new DelegatedCheckFailedException(sprintf('Unable to initialize cURL for: %s', $url));
        }

        $responseHeaders = [];

        curl_setopt($handle, CURLOPT_POST, true);
        curl_setopt($handle, CURLOPT_POSTFIELDS, $body);
        curl_setopt($handle, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($handle, CURLOPT_FOLLOWLOCATION, false);
        curl_setopt($handle, CURLOPT_TIMEOUT_MS, (int) ($timeoutSeconds * 1000));
        curl_setopt($handle, CURLOPT_CONNECTTIMEOUT_MS, (int) ($timeoutSeconds * 1000));
        curl_setopt($handle, CURLOPT_USERAGENT, Version::USER_AGENT);
        curl_setopt($handle, CURLOPT_HTTPHEADER, [
            'Content-Type: application/json',
            SignatureHeaders::KEY_ID . ': ' . $key->keyId,
            SignatureHeaders::TIMESTAMP . ': ' . $timestamp,
            SignatureHeaders::NONCE . ': ' . $nonce,
            SignatureHeaders::REQUEST_ID . ': ' . $requestId,
            SignatureHeaders::SIGNATURE . ': ' . $signature,
        ]);
        curl_setopt($handle, CURLOPT_HEADERFUNCTION, function ($ch, string $line) use (&$responseHeaders): int {
            if (preg_match('/^([^:]+):\s*(.*?)\r?\n$/', $line, $matches) === 1) {
                $responseHeaders[strtolower($matches[1])] = trim($matches[2]);
            }

            return strlen($line);
        });

        $responseBody = curl_exec($handle);
        if ($responseBody === false || !is_string($responseBody)) {
            $error = curl_error($handle);

            throw new DelegatedCheckFailedException($error !== '' ? $error : 'Unknown cURL error.');
        }

        $statusCode = (int) curl_getinfo($handle, CURLINFO_RESPONSE_CODE);

        if ($statusCode !== 200) {
            throw new DelegatedCheckFailedException(sprintf('Peer answered with HTTP %d.', $statusCode));
        }

        return $this->verifiedResult($responseHeaders, $responseBody, $statusCode, $requestId, $key->secret, $key->keyId);
    }

    /** @return array<string, mixed> */
    private function payloadFor(Site $site, float $timeoutSeconds): array
    {
        return [
            'check_type' => $site->checkType->value,
            'url' => $site->url,
            'host' => $site->host,
            'port' => $site->port,
            'keyword' => $site->keyword,
            'keyword_mode' => $site->keywordMode?->value,
            'timeout_seconds' => $timeoutSeconds,
            'challenge_delivery' => $site->challengeDelivery?->value,
        ];
    }

    /** @param array<string, string> $responseHeaders */
    private function verifiedResult(array $responseHeaders, string $responseBody, int $statusCode, string $requestId, string $secret, string $keyId): DelegatedCheckResult
    {
        $responseRequestId  = $responseHeaders[strtolower(SignatureHeaders::REQUEST_ID)] ?? '';
        $responseTimestamp  = $responseHeaders[strtolower(SignatureHeaders::RESPONSE_TIMESTAMP)] ?? '';
        $responseSignature  = $responseHeaders[strtolower(SignatureHeaders::SIGNATURE)] ?? '';

        if ($responseRequestId === '' || $responseTimestamp === '' || $responseSignature === '') {
            throw new DelegatedCheckFailedException('Peer response is missing signature headers.');
        }

        if (!hash_equals($requestId, $responseRequestId)) {
            throw new DelegatedCheckFailedException('Peer response request ID does not match.');
        }

        $canonical = $this->responseCanonicalizer->canonicalize($responseRequestId, $responseTimestamp, $statusCode, $responseBody);
        if (!$this->signer->verify($canonical, $secret, $responseSignature)) {
            throw new DelegatedCheckFailedException('Peer response signature is invalid.');
        }

        $decoded = json_decode($responseBody, true);
        if (!is_array($decoded) || !isset($decoded['outcome'])) {
            throw new DelegatedCheckFailedException('Peer response body is malformed.');
        }

        $outcome = CheckOutcome::tryFrom((string) $decoded['outcome']);
        if ($outcome === null) {
            throw new DelegatedCheckFailedException('Peer response has an unrecognized outcome.');
        }

        $result = new CheckResult(
            $outcome,
            $decoded['http_status'] ?? null,
            $decoded['response_time_ms'] ?? null,
            $decoded['keyword_matched'] ?? null,
            $decoded['error'] ?? null,
        );

        $evidence = is_array($decoded['evidence'] ?? null) ? $decoded['evidence'] : null;

        return new DelegatedCheckResult(
            $result,
            $keyId,
            $evidence,
            (string) ($decoded['attestation'] ?? 'none'),
            (bool) ($decoded['served_from_cache'] ?? false),
            (int) ($decoded['cache_age_seconds'] ?? 0),
        );
    }
}

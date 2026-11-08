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

namespace Uphptime\Pinger\Http;

use CurlHandle;
use DateTimeImmutable;
use RuntimeException;
use Uphptime\Check\Http\HttpClientInterface;
use Uphptime\Check\Http\HttpResponse;
use Uphptime\Pinger\DnsResolverInterface;
use Uphptime\Pinger\EgressPolicyException;
use Uphptime\Pinger\EgressPolicyInterface;
use Uphptime\Pinger\PreferredIpSelector;
use Uphptime\Site\ChallengeDelivery;
use Uphptime\Version;

/**
 * Implements HttpClientInterface exactly like CurlHttpClient, but resolves each hostname once and
 * pins the validated IP via CURLOPT_RESOLVE (defeating DNS rebinding), follows redirects manually
 * with CURLOPT_FOLLOWLOCATION off so every hop is independently re-validated, and caps the response
 * body. Evidence from the most recent call is readable via lastEvidence() as a side channel, so the
 * Http/HttpKeywordChecker classes that depend on HttpClientInterface stay completely unaware of it.
 */
final class SafeHttpClient implements HttpClientInterface
{
    private ?HttpProbeEvidence $lastEvidence = null;

    public function __construct(
        private readonly DnsResolverInterface $dnsResolver,
        private readonly EgressPolicyInterface $egressPolicy,
        private readonly int $maxRedirects,
        private readonly int $responseSizeCapBytes,
        private readonly PreferredIpSelector $preferredIpSelector,
    ) {}

    public function get(string $url, float $timeoutSeconds, string $userAgent, ?ChallengeDelivery $challengeDelivery = null): HttpResponse
    {
        $this->lastEvidence = null;
        $probeStartedAt     = new DateTimeImmutable();

        // Stored site URLs sometimes carry a stray trailing newline/space from copy-paste; left in,
        // curl rejects the whole request with CURLE_URL_MALFORMAT before anything is attempted.
        $url                = trim($url);
        $currentUrl         = preg_match('#^[a-z][a-z0-9+.-]*://#i', $url) === 1 ? $url : 'http://' . $url;
        $redirectsFollowed  = 0;

        while (true) {
            $target = $this->resolveAndValidate($currentUrl);
            $probe  = $this->executeOnce($currentUrl, $target, $timeoutSeconds, $userAgent, $redirectsFollowed, 'check');

            $isRedirect = in_array($probe['status'], [301, 302, 303, 307, 308], true);
            if ($isRedirect && $probe['location'] !== null && $redirectsFollowed < $this->maxRedirects) {
                $currentUrl = $this->resolveRedirectLocation($currentUrl, $probe['location']);
                $redirectsFollowed++;
                continue;
            }

            if ($isRedirect && $this->maxRedirects > 0) {
                if ($probe['location'] === null) {
                    throw new RuntimeException('Redirect response is missing a Location header.');
                }

                throw new RuntimeException(sprintf('Redirect limit of %d exceeded.', $this->maxRedirects));
            }

            $this->lastEvidence = new HttpProbeEvidence(
                resolvedIp: $target['ip'],
                tlsFingerprintSha256: $probe['tlsFingerprintSha256'],
                tlsExpiry: $probe['tlsExpiry'],
                responseDateHeader: $probe['dateHeader'],
                headersHash: hash('sha256', $probe['rawHeaders']),
                bodyHash: hash('sha256', $probe['body']),
                timingMs: $probe['timingMs'],
                probeStartedAt: $probeStartedAt,
                probeEndedAt: new DateTimeImmutable(),
                challengeTokenObserved: $this->observeChallengeToken($challengeDelivery, $currentUrl, $target, $probe['rawHeaders'], $timeoutSeconds, $userAgent),
            );

            return new HttpResponse($probe['status'], $probe['body'], $probe['elapsedMs']);
        }
    }

    public function lastEvidence(): ?HttpProbeEvidence
    {
        return $this->lastEvidence;
    }

    /** @return array{0: ?string, 1: ?DateTimeImmutable} */
    private function certificateEvidence(CurlHandle $handle): array
    {
        $certInfo = curl_getinfo($handle, CURLINFO_CERTINFO);
        if (!isset($certInfo[0]['Cert'])) {
            return [null, null];
        }

        $parsed = openssl_x509_parse($certInfo[0]['Cert']);
        if ($parsed === false) {
            return [null, null];
        }

        $fingerprint = openssl_x509_fingerprint($certInfo[0]['Cert'], 'sha256');
        $expiry      = isset($parsed['validTo_time_t']) ? (new DateTimeImmutable())->setTimestamp((int) $parsed['validTo_time_t']) : null;

        return [$fingerprint !== false ? $fingerprint : null, $expiry];
    }

    /**
     * @param array{host: string, port: int, ip: string} $target
     * @return array{status: int, body: string, elapsedMs: int, location: ?string, rawHeaders: string, dateHeader: ?string, tlsFingerprintSha256: ?string, tlsExpiry: ?DateTimeImmutable, timingMs: array{dns: ?int, connect: ?int, tls: ?int, first_byte: ?int, total: ?int}}
     */
    private function executeOnce(string $url, array $target, float $timeoutSeconds, string $userAgent, int $redirectsFollowed, string $operation): array
    {
        if ($url === '') {
            throw new RuntimeException('Empty URL.');
        }

        $handle = curl_init();
        if ($handle === false) {
            throw new RuntimeException(sprintf('Unable to initialize cURL for: %s', $url));
        }

        $rawHeaders = '';
        $dateHeader = null;
        $location   = null;
        $body       = '';
        $bodyBytes  = 0;
        $cap        = $this->responseSizeCapBytes;

        $timeoutMs = (int) ($timeoutSeconds * 1000);

        curl_setopt($handle, CURLOPT_URL, $url);
        curl_setopt($handle, CURLOPT_RESOLVE, [sprintf('%s:%d:%s', $target['host'], $target['port'], $target['ip'])]);
        curl_setopt($handle, CURLOPT_RETURNTRANSFER, false);
        curl_setopt($handle, CURLOPT_FOLLOWLOCATION, false);
        curl_setopt($handle, CURLOPT_TIMEOUT_MS, $timeoutMs);
        curl_setopt($handle, CURLOPT_CONNECTTIMEOUT_MS, $timeoutMs);
        curl_setopt($handle, CURLOPT_USERAGENT, $userAgent !== '' ? $userAgent : Version::USER_AGENT);
        curl_setopt($handle, CURLOPT_CERTINFO, true);
        curl_setopt($handle, CURLOPT_HEADERFUNCTION, function ($ch, string $line) use (&$rawHeaders, &$dateHeader, &$location): int {
            $rawHeaders .= $line;
            if (preg_match('/^Date:\s*(.+?)\r?\n$/i', $line, $matches) === 1) {
                $dateHeader = trim($matches[1]);
            }
            if (preg_match('/^Location:\s*(.+?)\r?\n$/i', $line, $matches) === 1) {
                $location = trim($matches[1]);
            }

            return strlen($line);
        });
        curl_setopt($handle, CURLOPT_WRITEFUNCTION, function ($ch, string $chunk) use (&$body, &$bodyBytes, $cap): int {
            $bodyBytes += strlen($chunk);
            if ($bodyBytes > $cap) {
                return 0; // a short count aborts the transfer, per curl's write-callback contract
            }

            $body .= $chunk;

            return strlen($chunk);
        });

        $start = microtime(true);
        $ok    = curl_exec($handle);

        if ($ok === false) {
            $errno = curl_errno($handle);
            $error = curl_error($handle);

            $this->logCurlFailure($url, $target, $errno, $redirectsFollowed, $operation);

            if ($errno === CURLE_WRITE_ERROR) {
                throw new RuntimeException(sprintf('Response exceeded the size cap of %d bytes.', $cap));
            }

            throw new RuntimeException($error !== '' ? $error : 'Unknown cURL error.');
        }

        $elapsedMs = (int) round((microtime(true) - $start) * 1000);
        $status    = (int) curl_getinfo($handle, CURLINFO_RESPONSE_CODE);
        $isHttps   = str_starts_with(strtolower($url), 'https://');

        $tlsFingerprint = null;
        $tlsExpiry      = null;
        if ($isHttps) {
            [$tlsFingerprint, $tlsExpiry] = $this->certificateEvidence($handle);
        }

        $timingMs = $this->timingEvidence($handle, $isHttps);

        return [
            'status' => $status,
            'body' => $body,
            'elapsedMs' => $elapsedMs,
            'location' => $location,
            'rawHeaders' => $rawHeaders,
            'dateHeader' => $dateHeader,
            'tlsFingerprintSha256' => $tlsFingerprint,
            'tlsExpiry' => $tlsExpiry,
            'timingMs' => $timingMs,
        ];
    }

    /** @param array{host: string, port: int, ip: string} $target */
    private function fetchWellKnownToken(string $url, array $target, float $timeoutSeconds, string $userAgent): ?string
    {
        $scheme       = strtolower(parse_url($url, PHP_URL_SCHEME) ?: 'http');
        $wellKnownUrl = sprintf('%s://%s:%d/.well-known/uphptime', $scheme, $target['host'], $target['port']);

        try {
            $probe = $this->executeOnce($wellKnownUrl, $target, $timeoutSeconds, $userAgent, 0, 'challenge');
        } catch (RuntimeException) {
            return null;
        }

        return $probe['status'] >= 200 && $probe['status'] < 300 ? trim($probe['body']) : null;
    }

    /** @param array{host: string, port: int, ip: string} $target */
    private function logCurlFailure(string $url, array $target, int $errno, int $redirectsFollowed, string $operation): void
    {
        $parts   = parse_url($url);
        $query   = is_array($parts) ? ($parts['query'] ?? null) : null;
        $message = [
            'event' => 'pinger_http_curl_failure',
            'operation' => $operation,
            'scheme' => is_array($parts) ? ($parts['scheme'] ?? null) : null,
            'host' => $target['host'],
            'port' => $target['port'],
            'path' => is_array($parts) ? ($parts['path'] ?? '/') : null,
            'query_present' => $query !== null,
            'query_contains_whitespace' => $query !== null && preg_match('/\s/', $query) === 1,
            'url_contains_whitespace' => preg_match('/\s/', $url) === 1,
            'redirects_followed' => $redirectsFollowed,
            'max_redirects' => $this->maxRedirects,
            'curl_errno' => $errno,
            'curl_error' => curl_strerror($errno),
        ];

        error_log((string) json_encode($message, JSON_INVALID_UTF8_SUBSTITUTE | JSON_UNESCAPED_SLASHES));
    }

    // Returns null on any failure (missing header, unreachable well-known path) rather than throwing -
    // a missing token is for the owner's verifier to judge, never a reason to fail the probe itself.
    /** @param array{host: string, port: int, ip: string} $target */
    private function observeChallengeToken(?ChallengeDelivery $challengeDelivery, string $url, array $target, string $rawHeaders, float $timeoutSeconds, string $userAgent): ?string
    {
        if ($challengeDelivery === ChallengeDelivery::Header) {
            if (preg_match('/^X-Uphptime-Challenge:\s*(.+)$/im', $rawHeaders, $matches) === 1) {
                return trim($matches[1]);
            }

            return null;
        }

        if ($challengeDelivery === ChallengeDelivery::WellKnown) {
            return $this->fetchWellKnownToken($url, $target, $timeoutSeconds, $userAgent);
        }

        return null;
    }

    /** @return array{host: string, port: int, ip: string} */
    private function resolveAndValidate(string $url): array
    {
        $parts = parse_url($url);
        if ($parts === false || !isset($parts['host'])) {
            throw new RuntimeException(sprintf('Unable to parse URL: %s', $url));
        }

        $scheme = strtolower($parts['scheme'] ?? 'http');
        $host   = $parts['host'];
        $port   = $parts['port'] ?? ($scheme === 'https' ? 443 : 80);

        $ips = $this->dnsResolver->resolve($host);
        if ($ips === []) {
            throw new RuntimeException(sprintf('Unable to resolve host: %s', $host));
        }

        $ip = $this->preferredIpSelector->choose($ips);
        if ($this->egressPolicy->isIpBlocked($ip)) {
            throw new EgressPolicyException(sprintf('Target IP is blocked by the egress policy: %s', $ip));
        }

        if (!$this->egressPolicy->isTargetAllowed($host, $ip)) {
            throw new EgressPolicyException(sprintf('Target is not in the calling peer\'s allowlist: %s', $host));
        }

        return ['host' => $host, 'port' => $port, 'ip' => $ip];
    }

    private function resolveRedirectLocation(string $baseUrl, string $location): string
    {
        if (preg_match('#^[a-z][a-z0-9+.-]*://#i', $location) === 1) {
            return $location;
        }

        $base = parse_url($baseUrl);
        if ($base === false || !isset($base['scheme'], $base['host'])) {
            throw new RuntimeException(sprintf('Unable to resolve redirect against: %s', $baseUrl));
        }

        $port      = isset($base['port']) ? ':' . $base['port'] : '';
        $authority = $base['scheme'] . '://' . $base['host'] . $port;

        return str_starts_with($location, '/') ? $authority . $location : $authority . '/' . $location;
    }

    /** @return array{dns: ?int, connect: ?int, tls: ?int, first_byte: ?int, total: ?int} */
    private function timingEvidence(CurlHandle $handle, bool $isHttps): array
    {
        $nameLookup    = (float) curl_getinfo($handle, CURLINFO_NAMELOOKUP_TIME);
        $connect       = (float) curl_getinfo($handle, CURLINFO_CONNECT_TIME);
        $appConnect    = (float) curl_getinfo($handle, CURLINFO_APPCONNECT_TIME);
        $startTransfer = (float) curl_getinfo($handle, CURLINFO_STARTTRANSFER_TIME);
        $total         = (float) curl_getinfo($handle, CURLINFO_TOTAL_TIME);

        $firstByteBase = $isHttps ? $appConnect : $connect;

        return [
            'dns' => (int) round($nameLookup * 1000),
            'connect' => (int) round(($connect - $nameLookup) * 1000),
            'tls' => $isHttps ? (int) round(($appConnect - $connect) * 1000) : null,
            'first_byte' => (int) round(($startTransfer - $firstByteBase) * 1000),
            'total' => (int) round($total * 1000),
        ];
    }
}

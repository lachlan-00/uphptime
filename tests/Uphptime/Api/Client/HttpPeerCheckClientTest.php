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

namespace Uphptime\Tests\Api\Client;

use DateTimeImmutable;
use PHPUnit\Framework\TestCase;
use Uphptime\Api\Client\DelegatedCheckFailedException;
use Uphptime\Api\Client\HttpPeerCheckClient;
use Uphptime\Api\Signing\HmacSigner;
use Uphptime\Api\Signing\RequestCanonicalizer;
use Uphptime\Api\Signing\ResponseCanonicalizer;
use Uphptime\Check\CheckOutcome;
use Uphptime\Peer\Peer;
use Uphptime\Peer\PeerKey;
use Uphptime\Site\CheckType;
use Uphptime\Site\Site;
use Uphptime\Site\SiteRuntimeState;
use Uphptime\Site\SiteState;
use Uphptime\Tests\Support\FakeClock;
use Uphptime\Tests\Support\SpawnedPhpServer;
use Uphptime\Version;

final class HttpPeerCheckClientTest extends TestCase
{
    private const SECRET = 'shared-secret-bytes';

    private static string $docRoot;
    private static SpawnedPhpServer $server;

    public static function setUpBeforeClass(): void
    {
        self::$docRoot = sys_get_temp_dir() . '/uphptime-peer-client-' . bin2hex(random_bytes(4));
        mkdir(self::$docRoot, 0775, true);

        file_put_contents(self::$docRoot . '/router.php', <<<'PHP'
            <?php
            $secret = 'shared-secret-bytes';
            $path = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
            $requestId = $_SERVER['HTTP_X_UPHPTIME_REQUEST_ID'] ?? '';
            $keyId = $_SERVER['HTTP_X_UPHPTIME_KEY_ID'] ?? '';

            if (str_contains($path, '/slow')) {
                usleep(500000);
            }

            $body = json_encode([
                'outcome' => 'pass', 'http_status' => 200, 'response_time_ms' => 12,
                'keyword_matched' => null, 'error' => null, 'evidence' => null, 'attestation' => 'none',
            ]);
            $status = 200;
            $responseTimestamp = (string) time();
            $canonical = implode("\n", [$requestId, $responseTimestamp, (string) $status, hash('sha256', $body)]);
            $signature = str_contains($path, '/tampered') ? str_repeat('0', 64) : hash_hmac('sha256', $canonical, $secret);

            if (str_contains($path, '/record-headers')) {
                $rawBody = file_get_contents('php://input');
                $requestCanonical = implode("\n", [
                    'POST', '/peer/v1/check-site', $keyId,
                    $_SERVER['HTTP_X_UPHPTIME_TIMESTAMP'] ?? '',
                    $_SERVER['HTTP_X_UPHPTIME_NONCE'] ?? '',
                    $requestId, hash('sha256', $rawBody),
                ]);
                $expected = hash_hmac('sha256', $requestCanonical, $secret);
                file_put_contents(__DIR__ . '/last-headers.json', json_encode([
                    'key_id' => $keyId,
                    'user_agent' => $_SERVER['HTTP_USER_AGENT'] ?? '',
                    'signature_valid' => hash_equals($expected, $_SERVER['HTTP_X_UPHPTIME_SIGNATURE'] ?? ''),
                ]));
            }

            http_response_code($status);
            header('X-Uphptime-Request-Id: ' . $requestId);
            header('X-Uphptime-Response-Timestamp: ' . $responseTimestamp);
            header('X-Uphptime-Signature: ' . $signature);
            echo $body;
            PHP);

        self::$server = new SpawnedPhpServer(self::$docRoot, self::$docRoot . '/router.php');
    }

    public static function tearDownAfterClass(): void
    {
        self::$server->stop();
        foreach (glob(self::$docRoot . '/*') ?: [] as $file) {
            @unlink($file);
        }
        @rmdir(self::$docRoot);
    }

    public function testOutboundRequestCarriesWellFormedSignatureHeadersVerifiedServerSide(): void
    {
        $client = $this->client();
        $peer   = $this->peer(self::$server->baseUrl() . '/record-headers');

        $client->requestCheck($peer, $this->site(), 5.0);

        $recorded = json_decode((string) file_get_contents(self::$docRoot . '/last-headers.json'), true);
        self::assertIsArray($recorded);
        self::assertSame('k1', $recorded['key_id']);
        self::assertSame(Version::USER_AGENT, $recorded['user_agent']);
        self::assertTrue((bool) $recorded['signature_valid']);
    }

    public function testTamperedResponseSignatureIsRejected(): void
    {
        $client = $this->client();
        $peer   = $this->peer(self::$server->baseUrl() . '/tampered');

        $this->expectException(DelegatedCheckFailedException::class);
        $client->requestCheck($peer, $this->site(), 5.0);
    }

    public function testTimeoutRaisesDelegatedCheckFailedException(): void
    {
        $client = $this->client();
        $peer   = $this->peer(self::$server->baseUrl() . '/slow');

        $this->expectException(DelegatedCheckFailedException::class);
        $client->requestCheck($peer, $this->site(), 0.2);
    }

    public function testValidSignedResponseIsParsedIntoADelegatedCheckResult(): void
    {
        $client = $this->client();
        $peer   = $this->peer(self::$server->baseUrl() . '/ok');

        $result = $client->requestCheck($peer, $this->site(), 5.0);

        self::assertSame('k1', $result->verifiedKeyId);
        self::assertSame(CheckOutcome::Pass, $result->result->outcome);
        self::assertSame('none', $result->attestation);
    }

    private function client(): HttpPeerCheckClient
    {
        return new HttpPeerCheckClient(
            new HmacSigner(),
            new RequestCanonicalizer(),
            new ResponseCanonicalizer(),
            new FakeClock(),
        );
    }

    private function peer(string $baseUrl): Peer
    {
        return new Peer(
            'peer-a',
            'Peer A',
            $baseUrl,
            true,
            true,
            null,
            [],
            [new PeerKey('k1', self::SECRET, true, true, true, new DateTimeImmutable('2026-01-01T00:00:00+00:00'))],
        );
    }

    private function site(): Site
    {
        $now = new DateTimeImmutable('2026-01-01T00:00:00+00:00');

        return new Site('s1', 'Site', 'https://example.test/', null, null, CheckType::Http, null, null, null, true, $now, new SiteRuntimeState(SiteState::Unknown, 0, null, null, $now, null));
    }
}

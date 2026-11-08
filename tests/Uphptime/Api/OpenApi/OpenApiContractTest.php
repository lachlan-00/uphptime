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

namespace Uphptime\Tests\Api\OpenApi;

use DateTimeImmutable;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use Uphptime\Api\OpenApi\OpenApiSpecBuilder;
use Uphptime\Api\Signing\HmacSigner;
use Uphptime\Api\Signing\RequestCanonicalizer;
use Uphptime\Api\Signing\SignatureHeaders;
use Uphptime\Peer\Peer;
use Uphptime\Peer\PeerKey;
use Uphptime\Site\ChallengeDelivery;
use Uphptime\Site\CheckType;
use Uphptime\Site\Site;
use Uphptime\Site\SiteRuntimeState;
use Uphptime\Site\SiteState;
use Uphptime\Storage\JsonPeerRepository;
use Uphptime\Storage\JsonSiteRepository;
use Uphptime\Support\AtomicWriter;
use Uphptime\Tests\Support\OpenApiResponseValidator;
use Uphptime\Tests\Support\SpawnedPhpServer;

/**
 * Fires real signed/unsigned HTTP requests at a real spawned public/index.php and validates the real
 * responses against docs/openapi.json - the spec is tested against the implementation, not just itself.
 */
final class OpenApiContractTest extends TestCase
{
    private const KEY_ID         = 'contract-test-key';
    private const REPORT_API_KEY = 'contract-report-key';
    private const SECRET         = 'contract-test-secret';
    private const SITE_ID        = 'demo-site';

    private static SpawnedPhpServer $monitoredSite;
    private static string $monitoredSiteDocRoot;
    private static SpawnedPhpServer $node;
    private static string $nodeDir;
    private static OpenApiResponseValidator $validator;

    public static function setUpBeforeClass(): void
    {
        self::$monitoredSiteDocRoot = sys_get_temp_dir() . '/uphptime-openapi-site-' . bin2hex(random_bytes(4));
        mkdir(self::$monitoredSiteDocRoot, 0775, true);
        file_put_contents(self::$monitoredSiteDocRoot . '/index.php', "<?php echo 'ok';");
        self::$monitoredSite = new SpawnedPhpServer(self::$monitoredSiteDocRoot);

        self::$nodeDir = sys_get_temp_dir() . '/uphptime-openapi-node-' . bin2hex(random_bytes(4));
        self::writeNodeConfig(self::$nodeDir, self::REPORT_API_KEY);
        self::seedPeer(self::$nodeDir);
        self::seedSite(self::$nodeDir);

        $repoRoot   = dirname(__DIR__, 4);
        self::$node = new SpawnedPhpServer($repoRoot . '/public', $repoRoot . '/public/index.php', [
            'UPHPTIME_CONFIG_PATH' => self::$nodeDir . '/config/node.json',
        ]);

        self::$validator = new OpenApiResponseValidator((new OpenApiSpecBuilder())->build());
    }

    public static function tearDownAfterClass(): void
    {
        self::$node->stop();
        self::$monitoredSite->stop();
        foreach (glob(self::$monitoredSiteDocRoot . '/*') ?: [] as $file) {
            @unlink($file);
        }
        @rmdir(self::$monitoredSiteDocRoot);
    }

    private static function seedPeer(string $nodeDir): void
    {
        $repository = new JsonPeerRepository(new AtomicWriter(), $nodeDir . '/data/peers.json');
        $repository->save(new Peer(
            'owner-under-test',
            'owner-under-test',
            null,
            true,
            true,
            null,
            [],
            [new PeerKey(self::KEY_ID, self::SECRET, false, true, true, new DateTimeImmutable())],
        ));
    }

    private static function seedSite(string $nodeDir): void
    {
        $now        = new DateTimeImmutable();
        $repository = new JsonSiteRepository(new AtomicWriter(), $nodeDir . '/data/sites.json');
        $repository->save(new Site(
            id: self::SITE_ID,
            name: 'Demo site',
            url: 'https://example.test/',
            host: null,
            port: null,
            checkType: CheckType::Http,
            keyword: null,
            keywordMode: null,
            checkIntervalSeconds: null,
            enabled: true,
            createdAt: $now,
            state: new SiteRuntimeState(SiteState::Unknown, 0, null, null, $now, null),
            maintenanceWindows: [],
            challengeDelivery: ChallengeDelivery::Header,
        ));
    }

    private static function writeNodeConfig(string $directory, string $reportApiKey = ''): void
    {
        mkdir($directory . '/config', 0775, true);
        mkdir($directory . '/data', 0775, true);

        file_put_contents($directory . '/config/node.json', (string) json_encode([
            'schema_version' => 1,
            'data_dir' => $directory . '/data',
            'check_interval_seconds' => 300,
            'retention_days' => 90,
            'request_timeout_seconds' => 5,
            'run_budget_seconds' => 60,
            'stale_after_seconds' => 900,
            'http_user_agent' => 'uphptime-openapi-contract-test',
            'timezone' => 'UTC',
            'pinger_enabled' => true,
            'clock_skew_seconds' => 60,
            'default_peer_rate_limit_per_minute' => 60,
            'peer_request_timeout_seconds' => 5,
            'pinger_max_timeout_seconds' => 15,
            'max_redirects' => 3,
            'response_size_cap_bytes' => 2_097_152,
            'tcp_port_allowlist' => [80, 443],
            'egress_allowed_ranges' => ['127.0.0.0/8'],
            'report_api_key' => $reportApiKey,
        ]));
    }

    public function testChallengeTokenConfiguredSiteIs200AndMatchesSchema(): void
    {
        [$status, , $body] = $this->rawGet('/challenge/v1/' . self::SITE_ID);

        self::assertSame(200, $status);
        self::assertSame([], self::$validator->errorsFor('/challenge/v1/{site}', 'get', 200, $body));
    }

    public function testChallengeTokenUnknownSiteIs404AndMatchesSchema(): void
    {
        [$status, , $body] = $this->rawGet('/challenge/v1/does-not-exist');

        self::assertSame(404, $status);
        self::assertSame([], self::$validator->errorsFor('/challenge/v1/{site}', 'get', 404, $body));
    }

    public function testCheckSiteInvalidJsonBodyIs422AndMatchesSchema(): void
    {
        [$status, $headers, $body] = $this->signedPost('not json', valid: true);

        self::assertSame(422, $status);
        self::assertSame('invalid_json', $body['error'] ?? null);
        self::assertSame([], self::$validator->errorsFor('/peer/v1/check-site', 'post', 422, $body));
        self::assertArrayHasKey(strtolower(SignatureHeaders::SIGNATURE), $headers, '422 responses are post-auth and must still be signed');
    }

    public function testCheckSiteInvalidPayloadIs422AndMatchesSchema(): void
    {
        $payload           = (string) json_encode(['check_type' => 'http']); // missing required "url"
        [$status, , $body] = $this->signedPost($payload, valid: true);

        self::assertSame(422, $status);
        self::assertSame('invalid_payload', $body['error'] ?? null);
        self::assertSame([], self::$validator->errorsFor('/peer/v1/check-site', 'post', 422, $body));
    }

    public function testCheckSiteUnauthorizedIs401AndMatchesSchemaAndIsNeverSigned(): void
    {
        $payload                   = (string) json_encode(['check_type' => 'http', 'url' => self::$monitoredSite->baseUrl() . '/', 'timeout_seconds' => 5]);
        [$status, $headers, $body] = $this->signedPost($payload, valid: false);

        self::assertSame(401, $status);
        self::assertSame('unauthorized', $body['error'] ?? null);
        self::assertSame([], self::$validator->errorsFor('/peer/v1/check-site', 'post', 401, $body));
        self::assertArrayNotHasKey(strtolower(SignatureHeaders::SIGNATURE), $headers, 'a rejected request is never authenticated, so its response is never signed');
    }

    public function testCheckSiteValidRequestIs200AndMatchesSchema(): void
    {
        $payload                   = (string) json_encode(['check_type' => 'http', 'url' => self::$monitoredSite->baseUrl() . '/', 'timeout_seconds' => 5]);
        [$status, $headers, $body] = $this->signedPost($payload, valid: true);

        self::assertSame(200, $status);
        self::assertSame([], self::$validator->errorsFor('/peer/v1/check-site', 'post', 200, $body));
        self::assertArrayHasKey(strtolower(SignatureHeaders::SIGNATURE), $headers, '200 responses are post-auth and must be signed');
    }

    public function testReportOverviewUnconfiguredNodeIs404AndMatchesSchema(): void
    {
        $directory = sys_get_temp_dir() . '/uphptime-openapi-report-disabled-' . bin2hex(random_bytes(4));
        self::writeNodeConfig($directory);
        $repoRoot = dirname(__DIR__, 4);
        $server   = new SpawnedPhpServer($repoRoot . '/public', $repoRoot . '/public/index.php', ['UPHPTIME_CONFIG_PATH' => $directory . '/config/node.json']);

        try {
            [$status, , $body] = $this->rawGetWithAuthorization($server, '/report/v1/overview', 'Bearer anything');

            self::assertSame(404, $status);
            self::assertSame([], self::$validator->errorsFor('/report/v1/overview', 'get', 404, $body));
        } finally {
            $server->stop();
        }
    }

    public function testReportOverviewWithValidKeyIs200AndMatchesSchema(): void
    {
        [$status, , $body] = $this->rawGetWithAuthorization(self::$node, '/report/v1/overview', 'Bearer ' . self::REPORT_API_KEY);

        self::assertSame(200, $status);
        self::assertSame([], self::$validator->errorsFor('/report/v1/overview', 'get', 200, $body));
    }

    public function testReportOverviewWithWrongKeyIs401AndMatchesSchema(): void
    {
        [$status, , $body] = $this->rawGetWithAuthorization(self::$node, '/report/v1/overview', 'Bearer wrong-key');

        self::assertSame(401, $status);
        self::assertSame([], self::$validator->errorsFor('/report/v1/overview', 'get', 401, $body));
    }

    public function testRootReturnsAnEmptySuccessfulResponse(): void
    {
        [$status, , $body] = $this->curl(self::$node->baseUrl(), 'GET', '/?test=1', [], null);

        self::assertSame(200, $status);
        self::assertSame('', $body);
    }

    public function testUnknownRouteStillReturns404(): void
    {
        [$status] = $this->curl(self::$node->baseUrl(), 'GET', '/not-a-route', [], null);

        self::assertSame(404, $status);
    }

    /**
     * @return array{0: int, 1: array<string, string>, 2: string}
     */
    private function curl(string $baseUrl, string $method, string $path, array $headers, ?string $body): array
    {
        $handle = curl_init($baseUrl . $path);
        if ($handle === false) {
            throw new RuntimeException('Unable to initialize cURL.');
        }

        $responseHeaders = [];
        curl_setopt($handle, CURLOPT_CUSTOMREQUEST, $method);
        curl_setopt($handle, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($handle, CURLOPT_HTTPHEADER, $headers);
        if ($body !== null) {
            curl_setopt($handle, CURLOPT_POSTFIELDS, $body);
        }
        curl_setopt($handle, CURLOPT_HEADERFUNCTION, function ($ch, string $line) use (&$responseHeaders): int {
            if (preg_match('/^([^:]+):\s*(.*?)\r?\n$/', $line, $matches) === 1) {
                $responseHeaders[strtolower($matches[1])] = trim($matches[2]);
            }

            return strlen($line);
        });

        $rawBody = curl_exec($handle);
        $status  = (int) curl_getinfo($handle, CURLINFO_RESPONSE_CODE);

        if (!is_string($rawBody)) {
            throw new RuntimeException(curl_error($handle) ?: 'Unknown cURL error.');
        }

        return [$status, $responseHeaders, $rawBody];
    }

    /**
     * @return array{0: int, 1: array<string, string>, 2: array<string, mixed>}
     */
    private function rawGet(string $path): array
    {
        [$status, $headers, $rawBody] = $this->curl(self::$node->baseUrl(), 'GET', $path, [], null);
        $decoded                      = json_decode($rawBody, true);

        return [$status, $headers, is_array($decoded) ? $decoded : []];
    }

    /**
     * @return array{0: int, 1: array<string, string>, 2: array<string, mixed>}
     */
    private function rawGetWithAuthorization(SpawnedPhpServer $server, string $path, string $authorization): array
    {
        [$status, $headers, $rawBody] = $this->curl($server->baseUrl(), 'GET', $path, ['Authorization: ' . $authorization], null);
        $decoded                      = json_decode($rawBody, true);

        return [$status, $headers, is_array($decoded) ? $decoded : []];
    }

    /**
     * @return array{0: int, 1: array<string, string>, 2: array<string, mixed>}
     */
    private function signedPost(string $body, bool $valid): array
    {
        $timestamp = (string) (new DateTimeImmutable())->getTimestamp();
        $nonce     = bin2hex(random_bytes(16));
        $requestId = bin2hex(random_bytes(16));

        $canonical = (new RequestCanonicalizer())->canonicalize('POST', '/peer/v1/check-site', self::KEY_ID, $timestamp, $nonce, $requestId, $body);
        $signature = (new HmacSigner())->sign($canonical, $valid ? self::SECRET : 'wrong-secret');

        $headers = [
            'Content-Type: application/json',
            SignatureHeaders::KEY_ID . ': ' . self::KEY_ID,
            SignatureHeaders::TIMESTAMP . ': ' . $timestamp,
            SignatureHeaders::NONCE . ': ' . $nonce,
            SignatureHeaders::REQUEST_ID . ': ' . $requestId,
            SignatureHeaders::SIGNATURE . ': ' . $signature,
        ];

        [$status, $responseHeaders, $rawBody] = $this->curl(self::$node->baseUrl(), 'POST', '/peer/v1/check-site', $headers, $body);
        $decoded                              = json_decode($rawBody, true);

        return [$status, $responseHeaders, is_array($decoded) ? $decoded : []];
    }
}

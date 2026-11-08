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

namespace Uphptime\Tests\Support;

use DateTimeImmutable;
use Psr\Container\ContainerInterface;
use Uphptime\DicBuilder;
use Uphptime\Peer\Peer;
use Uphptime\Peer\PeerKey;
use Uphptime\Storage\JsonPeerRepository;
use Uphptime\Support\AtomicWriter;

/**
 * Two isolated uphptime node trees sharing one HMAC key: node B's pinger is a real spawned
 * `public/index.php` process (so the real HTTP/Slim/PSR-15/curl stack is exercised); node A, the
 * owner, is driven in-process via its own DicBuilder container, since in production it is just a
 * cron-invoked CLI, never a server.
 */
final class TwoNodeHarness
{
    private readonly string $nodeADir;
    private readonly string $nodeBDir;
    private ?ContainerInterface $ownerContainer = null;
    private SpawnedPhpServer $pingerServer;
    private readonly string $repoRoot;
    private readonly string $secret;

    public function __construct()
    {
        $this->repoRoot = dirname(__DIR__, 3);
        $this->secret   = random_bytes(32);

        $this->nodeADir = sys_get_temp_dir() . '/uphptime-node-a-' . bin2hex(random_bytes(4));
        $this->nodeBDir = sys_get_temp_dir() . '/uphptime-node-b-' . bin2hex(random_bytes(4));

        $this->writeNodeConfig($this->nodeADir);
        $this->writeNodeConfig($this->nodeBDir);

        // Node B only needs to accept inbound calls signed with the shared key; it never calls A.
        $this->seedPeer($this->nodeBDir, 'node-a', null, outbound: false, inbound: true);

        $this->pingerServer = new SpawnedPhpServer(
            $this->repoRoot . '/public',
            $this->repoRoot . '/public/index.php',
            ['UPHPTIME_CONFIG_PATH' => $this->nodeBConfigPath()],
        );

        // Node A delegates outbound calls to B, now that B's randomly-chosen port is known.
        $this->seedPeer($this->nodeADir, 'node-b', $this->pingerServer->baseUrl(), outbound: true, inbound: false);
    }

    public function cleanup(): void
    {
        $this->removeTree($this->nodeADir);
        $this->removeTree($this->nodeBDir);
    }

    public function ownerContainer(): ContainerInterface
    {
        if ($this->ownerContainer === null) {
            $this->ownerContainer = (new DicBuilder())->build($this->nodeAConfigPath());
        }

        return $this->ownerContainer;
    }

    public function pingerBaseUrl(): string
    {
        return $this->pingerServer->baseUrl();
    }

    public function stopPinger(): void
    {
        $this->pingerServer->stop();
    }

    private function nodeAConfigPath(): string
    {
        return $this->nodeADir . '/config/node.json';
    }

    private function nodeBConfigPath(): string
    {
        return $this->nodeBDir . '/config/node.json';
    }

    private function removeTree(string $directory): void
    {
        if (!is_dir($directory)) {
            return;
        }

        foreach (glob($directory . '/*/*') ?: [] as $file) {
            @unlink($file);
        }
        foreach (glob($directory . '/*') ?: [] as $entry) {
            if (is_dir($entry)) {
                foreach (glob($entry . '/*') ?: [] as $file) {
                    @unlink($file);
                }
                @rmdir($entry);
            } else {
                @unlink($entry);
            }
        }
        @rmdir($directory);
    }

    private function seedPeer(string $nodeDir, string $label, ?string $baseUrl, bool $outbound, bool $inbound): void
    {
        $repository = new JsonPeerRepository(new AtomicWriter(), $nodeDir . '/data/peers.json');
        $repository->save(new Peer(
            $label,
            $label,
            $baseUrl,
            true,
            true,
            null,
            [],
            [new PeerKey('k-harness', $this->secret, $outbound, $inbound, true, new DateTimeImmutable())],
        ));
    }

    private function writeNodeConfig(string $directory): void
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
            'http_user_agent' => 'uphptime-test',
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
        ]));
    }
}

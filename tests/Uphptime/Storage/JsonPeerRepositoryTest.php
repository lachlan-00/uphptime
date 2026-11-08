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

namespace Uphptime\Tests\Storage;

use DateTimeImmutable;
use PHPUnit\Framework\TestCase;
use Uphptime\Peer\Peer;
use Uphptime\Peer\PeerKey;
use Uphptime\Storage\JsonPeerRepository;
use Uphptime\Support\AtomicWriter;

final class JsonPeerRepositoryTest extends TestCase
{
    private string $path;

    public function testAddListRemoveRoundTrip(): void
    {
        $repository = new JsonPeerRepository(new AtomicWriter(), $this->path);

        $repository->save($this->peer('a'));
        $repository->save($this->peer('b'));

        self::assertCount(2, $repository->all());
        self::assertNotNull($repository->find('a'));

        $repository->remove('a');

        self::assertCount(1, $repository->all());
        self::assertNull($repository->find('a'));
    }

    public function testConcurrentWritersForDifferentPeersBothPersist(): void
    {
        $repository = new JsonPeerRepository(new AtomicWriter(), $this->path);
        $repository->save($this->peer('seed'));

        $script = sys_get_temp_dir() . '/uphptime-peer-writer-' . bin2hex(random_bytes(4)) . '.php';
        file_put_contents($script, <<<'PHP'
            <?php
            require $argv[2];
            $repo = new Uphptime\Storage\JsonPeerRepository(new Uphptime\Support\AtomicWriter(), $argv[1]);
            $now = new DateTimeImmutable('2026-01-01T00:00:00+00:00');
            $key = new Uphptime\Peer\PeerKey('k-' . $argv[3], 'secret', true, true, true, $now);
            $peer = new Uphptime\Peer\Peer($argv[3], 'concurrent', 'https://example.test/', true, true, null, [], [$key]);
            $repo->save($peer);
            PHP);

        $autoload  = dirname(__DIR__, 3) . '/vendor/autoload.php';
        $processes = [];
        foreach (['x', 'y'] as $id) {
            $processes[] = proc_open(
                [PHP_BINARY, $script, $this->path, $autoload, $id],
                [1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
                $pipes,
            );
        }

        foreach ($processes as $process) {
            proc_close($process);
        }
        @unlink($script);

        $ids = array_map(static fn(Peer $peer): string => $peer->id, $repository->all());
        sort($ids);

        self::assertSame(['seed', 'x', 'y'], $ids);
    }

    public function testEnabledForDelegationExcludesDisabledAndNonDelegatingPeers(): void
    {
        $repository = new JsonPeerRepository(new AtomicWriter(), $this->path);
        $repository->save($this->peer('disabled', enabled: false));
        $repository->save($this->peer('no-delegation', allowOutboundDelegation: false));
        $repository->save($this->peer('eligible'));

        $candidates = $repository->enabledForDelegation();

        self::assertCount(1, $candidates);
        self::assertSame('eligible', $candidates[0]->id);
    }

    public function testFindByInboundKeyIdFindsTheOwningPeer(): void
    {
        $repository = new JsonPeerRepository(new AtomicWriter(), $this->path);
        $repository->save($this->peer('a'));

        $found = $repository->findByInboundKeyId('k-a');

        self::assertNotNull($found);
        self::assertSame('a', $found['peer']->id);
        self::assertSame('k-a', $found['key']->keyId);
    }

    public function testSecretRoundTripsThroughBase64Encoding(): void
    {
        $repository = new JsonPeerRepository(new AtomicWriter(), $this->path);
        $repository->save($this->peer('a'));

        $found = $repository->find('a');

        self::assertNotNull($found);
        self::assertSame('secret-bytes', $found->keys[0]->secret);
    }

    protected function setUp(): void
    {
        $this->path = sys_get_temp_dir() . '/uphptime-peers-' . bin2hex(random_bytes(4)) . '.json';
    }

    protected function tearDown(): void
    {
        @unlink($this->path);
        @unlink($this->path . '.lock');
    }

    private function peer(string $id, bool $enabled = true, bool $allowOutboundDelegation = true): Peer
    {
        $now = new DateTimeImmutable('2026-01-01T00:00:00+00:00');
        $key = new PeerKey('k-' . $id, 'secret-bytes', true, true, true, $now);

        return new Peer($id, 'Peer ' . $id, 'https://' . $id . '.test/', $enabled, $allowOutboundDelegation, null, [], [$key]);
    }
}

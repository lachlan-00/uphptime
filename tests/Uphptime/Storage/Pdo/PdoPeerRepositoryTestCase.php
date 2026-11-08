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

namespace Uphptime\Tests\Storage\Pdo;

use DateTimeImmutable;
use PDO;
use PHPUnit\Framework\TestCase;
use Uphptime\Peer\Peer;
use Uphptime\Peer\PeerKey;
use Uphptime\Storage\Pdo\PdoPeerRepository;

/**
 * Dialect-agnostic coverage for PdoPeerRepository, run against both real engines.
 */
abstract class PdoPeerRepositoryTestCase extends TestCase
{
    use PdoRepositoryTestHelpers;

    private PDO $pdo;

    // The defining case: two processes replacing the SAME peer's keys concurrently must never leave
    // a torn, half-replaced set - the transaction-wrapped delete-then-reinsert must always win whole.
    public function testConcurrentSamePeerKeyReplacementNeverLeavesATornSet(): void
    {
        $repository = $this->repository();
        $repository->save($this->peer('shared', [$this->key('k0')]));

        $scripts   = [];
        $processes = [];
        foreach (['ka', 'kb'] as $keyId) {
            [$process, $script]  = $this->spawnKeyReplacer($keyId);
            $processes[]         = $process;
            $scripts[]           = $script;
        }

        foreach ($processes as $process) {
            proc_close($process);
        }

        foreach ($scripts as $script) {
            @unlink($script);
        }

        $found = $repository->find('shared');
        self::assertNotNull($found);
        // Whichever writer ran last, the result is always exactly one complete key, never a mix.
        self::assertCount(1, $found->keys);
    }

    public function testConcurrentWritersForDifferentPeersBothPersist(): void
    {
        $repository = $this->repository();

        $scripts   = [];
        $processes = [];
        foreach (['x', 'y'] as $id) {
            [$process, $script]  = $this->spawnPeerWriter($id);
            $processes[]         = $process;
            $scripts[]           = $script;
        }

        foreach ($processes as $process) {
            proc_close($process);
        }

        foreach ($scripts as $script) {
            @unlink($script);
        }

        $ids = array_map(static fn(Peer $peer): string => $peer->id, $repository->all());
        sort($ids);

        self::assertSame(['x', 'y'], $ids);
    }

    public function testFindByInboundKeyId(): void
    {
        $repository = $this->repository();
        $repository->save($this->peer('a', [$this->key('k-a', inbound: true)]));
        $repository->save($this->peer('b', [$this->key('k-b', inbound: true)]));

        $found = $repository->findByInboundKeyId('k-b');

        self::assertNotNull($found);
        self::assertSame('b', $found['peer']->id);
        self::assertSame('k-b', $found['key']->keyId);
    }

    public function testKeysAndAllowlistPreserveInsertionOrder(): void
    {
        $repository = $this->repository();

        $peer = $this->peer('ordered', [$this->key('k1'), $this->key('k2'), $this->key('k3')]);
        $peer = new Peer(
            $peer->id,
            $peer->label,
            $peer->baseUrl,
            $peer->enabled,
            $peer->allowOutboundDelegation,
            $peer->rateLimitPerMinute,
            ['example.test', 'second.test', 'third.test'],
            $peer->keys,
        );

        $repository->save($peer);
        $found = $repository->find('ordered');

        self::assertNotNull($found);
        self::assertSame(['k1', 'k2', 'k3'], array_map(static fn(PeerKey $key): string => $key->keyId, $found->keys));
        self::assertSame(['example.test', 'second.test', 'third.test'], $found->targetAllowlist);
    }

    public function testRemoveCascadesToKeysAndAllowlist(): void
    {
        $repository = $this->repository();
        $peer       = $this->peer('a', [$this->key('k-a')]);
        $repository->save(new Peer($peer->id, $peer->label, $peer->baseUrl, $peer->enabled, $peer->allowOutboundDelegation, $peer->rateLimitPerMinute, ['example.test'], $peer->keys));

        $repository->remove('a');

        self::assertNull($repository->find('a'));
        self::assertNull($repository->findByInboundKeyId('k-a'));
    }

    public function testSaveReplacesKeysEntirelyRatherThanAppending(): void
    {
        $repository = $this->repository();
        $repository->save($this->peer('a', [$this->key('k1'), $this->key('k2')]));
        $repository->save($this->peer('a', [$this->key('k3')]));

        $found = $repository->find('a');
        self::assertNotNull($found);
        self::assertCount(1, $found->keys);
        self::assertSame('k3', $found->keys[0]->keyId);
    }

    abstract protected function dialectName(): string;

    protected function setUp(): void
    {
        $this->pdo = $this->connectOrSkip($this->dialectName());
    }

    private function key(string $keyId, bool $outbound = true, bool $inbound = true): PeerKey
    {
        return new PeerKey($keyId, random_bytes(32), $outbound, $inbound, true, new DateTimeImmutable('2026-01-01T00:00:00+00:00'));
    }

    /** @param PeerKey[] $keys */
    private function peer(string $id, array $keys): Peer
    {
        return new Peer($id, 'Peer ' . $id, 'https://' . $id . '.example.test', true, true, null, [], $keys);
    }

    private function repository(): PdoPeerRepository
    {
        return new PdoPeerRepository($this->pdo, $this->dialectFor($this->dialectName()));
    }

    /** @return array{0: resource, 1: string} */
    private function spawnKeyReplacer(string $keyId): array
    {
        $script = sys_get_temp_dir() . '/uphptime-pdo-peer-key-replacer-' . bin2hex(random_bytes(4)) . '.php';
        file_put_contents($script, <<<'PHP'
            <?php
            require $argv[2];
            $dialect = $argv[3] === 'pgsql' ? new Uphptime\Storage\Pdo\PostgreSqlDialect() : new Uphptime\Storage\Pdo\MySqlDialect();
            $pdo = Uphptime\Tests\Storage\Pdo\PdoTestConnection::attempt($argv[3]);
            $repo = new Uphptime\Storage\Pdo\PdoPeerRepository($pdo, $dialect);
            $now = new DateTimeImmutable('2026-01-01T00:00:00+00:00');
            $key = new Uphptime\Peer\PeerKey($argv[1], random_bytes(32), true, true, true, $now);
            $peer = new Uphptime\Peer\Peer('shared', 'Peer shared', 'https://shared.example.test', true, true, null, [], [$key]);
            $repo->save($peer);
            PHP);

        $autoload = dirname(__DIR__, 4) . '/vendor/autoload.php';
        $process  = proc_open(
            [PHP_BINARY, $script, $keyId, $autoload, $this->dialectName()],
            [1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $pipes,
        );

        return [$process, $script];
    }

    /** @return array{0: resource, 1: string} */
    private function spawnPeerWriter(string $id): array
    {
        $script = sys_get_temp_dir() . '/uphptime-pdo-peer-writer-' . bin2hex(random_bytes(4)) . '.php';
        file_put_contents($script, <<<'PHP'
            <?php
            require $argv[2];
            $dialect = $argv[3] === 'pgsql' ? new Uphptime\Storage\Pdo\PostgreSqlDialect() : new Uphptime\Storage\Pdo\MySqlDialect();
            $pdo = Uphptime\Tests\Storage\Pdo\PdoTestConnection::attempt($argv[3]);
            $repo = new Uphptime\Storage\Pdo\PdoPeerRepository($pdo, $dialect);
            $now = new DateTimeImmutable('2026-01-01T00:00:00+00:00');
            $key = new Uphptime\Peer\PeerKey('k-' . $argv[1], random_bytes(32), true, true, true, $now);
            $peer = new Uphptime\Peer\Peer($argv[1], 'Peer ' . $argv[1], 'https://' . $argv[1] . '.example.test', true, true, null, [], [$key]);
            $repo->save($peer);
            PHP);

        $autoload = dirname(__DIR__, 4) . '/vendor/autoload.php';
        $process  = proc_open(
            [PHP_BINARY, $script, $id, $autoload, $this->dialectName()],
            [1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $pipes,
        );

        return [$process, $script];
    }
}

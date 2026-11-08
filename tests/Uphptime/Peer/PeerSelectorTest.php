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

namespace Uphptime\Tests\Peer;

use DateTimeImmutable;
use PHPUnit\Framework\TestCase;
use Uphptime\Config\Config;
use Uphptime\Peer\Peer;
use Uphptime\Peer\PeerKey;
use Uphptime\Peer\PeerReputation;
use Uphptime\Peer\PeerReputationScorer;
use Uphptime\Peer\PeerSelector;
use Uphptime\Tests\Storage\InMemoryPeerRepository;
use Uphptime\Tests\Storage\InMemoryPeerReputationRepository;
use Uphptime\Tests\Support\FakeClock;
use Uphptime\Tests\Support\FakeRandom;

final class PeerSelectorTest extends TestCase
{
    public function testEmptyEligibleSetReturnsNull(): void
    {
        $selector = $this->selector(new InMemoryPeerRepository(), new InMemoryPeerReputationRepository());

        self::assertNull($selector->pick());
    }

    public function testExcludesExplicitlyPassedIds(): void
    {
        $peers = new InMemoryPeerRepository();
        $peers->save($this->peer('a'));
        $peers->save($this->peer('b'));

        $selector = $this->selector($peers, new InMemoryPeerReputationRepository());

        self::assertSame('b', $selector->pick(['a'])?->id);
    }

    public function testExcludesReputationExcludedPeers(): void
    {
        $peers = new InMemoryPeerRepository();
        $peers->save($this->peer('a'));
        $peers->save($this->peer('b'));

        $reputations = new InMemoryPeerReputationRepository();
        $reputations->save(new PeerReputation('a', 10.0, 1, new DateTimeImmutable('2026-01-01T01:00:00+00:00'), null, 1));

        $selector = $this->selector($peers, $reputations, now: '2026-01-01T00:00:00+00:00');

        self::assertSame('b', $selector->pick()?->id, 'the circuit-broken peer must never be picked');
    }

    public function testPickIsDrivenByTheInjectedRandom(): void
    {
        $peers = new InMemoryPeerRepository();
        $peers->save($this->peer('a'));
        $peers->save($this->peer('b'));

        $selector = $this->selector($peers, new InMemoryPeerReputationRepository());

        self::assertSame('a', $selector->pick()?->id, 'FakeRandom::pickIndex() always returns 0, the first eligible candidate');
    }

    private function peer(string $id): Peer
    {
        return new Peer($id, $id, 'https://' . $id . '.test/', true, true, null, [], [new PeerKey('k1', 'secret', true, true, true, new DateTimeImmutable('2026-01-01T00:00:00+00:00'))]);
    }

    private function selector(InMemoryPeerRepository $peers, InMemoryPeerReputationRepository $reputations, string $now = '2026-01-01T00:00:00+00:00'): PeerSelector
    {
        $config = new Config('data', 300, 90, 10, 240, 900, 'uphptime-test', 'UTC');

        return new PeerSelector($peers, $reputations, new PeerReputationScorer($config), new FakeClock($now), new FakeRandom());
    }
}

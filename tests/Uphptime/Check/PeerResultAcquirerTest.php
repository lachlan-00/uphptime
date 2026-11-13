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

namespace Uphptime\Tests\Check;

use DateTimeImmutable;
use PHPUnit\Framework\TestCase;
use Uphptime\Api\Client\DelegatedCheckResult;
use Uphptime\Check\AttestationVerifier;
use Uphptime\Check\CheckOutcome;
use Uphptime\Check\CheckResult;
use Uphptime\Check\PeerResultAcquirer;
use Uphptime\Config\Config;
use Uphptime\Peer\Peer;
use Uphptime\Peer\PeerKey;
use Uphptime\Peer\PeerReputationScorer;
use Uphptime\Peer\PeerSelector;
use Uphptime\Site\CheckType;
use Uphptime\Site\Site;
use Uphptime\Site\SiteRuntimeState;
use Uphptime\Site\SiteState;
use Uphptime\Tests\Api\ChallengeToken\InMemoryChallengeTokenStore;
use Uphptime\Tests\Api\Client\FakePeerCheckClient;
use Uphptime\Tests\Storage\InMemoryPeerRepository;
use Uphptime\Tests\Storage\InMemoryPeerReputationRepository;
use Uphptime\Tests\Support\FakeClock;
use Uphptime\Tests\Support\FakeRandom;

final class PeerResultAcquirerTest extends TestCase
{
    public function testExhaustsConfiguredAttemptsAndRecordsABadEventPerNonResponse(): void
    {
        $peers = new InMemoryPeerRepository();
        $peers->save($this->peer('a'));
        $peers->save($this->peer('b'));
        $peers->save($this->peer('c'));

        $client                   = FakePeerCheckClient::byPeerId(['a' => null, 'b' => null, 'c' => null]);
        [$acquirer, $reputations] = $this->acquirer($peers, $client);

        $result = $acquirer->acquire($this->site(), [], 10.0);

        self::assertNull($result);
        self::assertSame(80.0, $reputations->get('a')->score);
        self::assertSame(80.0, $reputations->get('b')->score);
        self::assertSame(80.0, $reputations->get('c')->score);
    }

    public function testStopsEarlyWhenFewerCandidatesThanTheMaxExist(): void
    {
        $peers = new InMemoryPeerRepository();
        $peers->save($this->peer('a'));

        $client     = FakePeerCheckClient::byPeerId(['a' => null]);
        [$acquirer] = $this->acquirer($peers, $client);

        self::assertNull($acquirer->acquire($this->site(), [], 10.0));
    }

    public function testErrorReplySettlesNothingAndTheNextPeerIsTried(): void
    {
        $peers = new InMemoryPeerRepository();
        $peers->save($this->peer('a'));
        $peers->save($this->peer('b'));

        $client = FakePeerCheckClient::byPeerId([
            'a' => new DelegatedCheckResult(CheckResult::error('target_not_allowed_for_peer'), 'k1', null, 'none'),
            'b' => new DelegatedCheckResult(CheckResult::fail('timeout'), 'k1', null, 'none'),
        ]);
        [$acquirer, $reputations] = $this->acquirer($peers, $client);

        $result = $acquirer->acquire($this->site(), [], 10.0);

        self::assertNotNull($result);
        self::assertSame(CheckOutcome::Fail, $result->result->outcome);
        self::assertSame('b', $result->peer?->id);
        self::assertSame(100.0, $reputations->get('a')->score, 'an error reply is a valid response, not a non-response');
    }

    public function testOnlyErrorRepliesYieldsNull(): void
    {
        $peers = new InMemoryPeerRepository();
        $peers->save($this->peer('a'));

        $client     = FakePeerCheckClient::byPeerId(['a' => new DelegatedCheckResult(CheckResult::error('target_not_allowed_for_peer'), 'k1', null, 'none')]);
        [$acquirer] = $this->acquirer($peers, $client);

        self::assertNull($acquirer->acquire($this->site(), [], 10.0));
    }

    public function testSucceedsOnTheSecondAttemptAfterOneNonResponse(): void
    {
        $peers = new InMemoryPeerRepository();
        $peers->save($this->peer('a'));
        $peers->save($this->peer('b'));

        $passResult               = new DelegatedCheckResult(CheckResult::pass(200, 5), 'k1', null, 'none');
        $client                   = FakePeerCheckClient::byPeerId(['a' => null, 'b' => $passResult]);
        [$acquirer, $reputations] = $this->acquirer($peers, $client);

        $result = $acquirer->acquire($this->site(), [], 10.0);

        self::assertNotNull($result);
        self::assertSame(CheckOutcome::Pass, $result->result->outcome);
        self::assertSame('b', $result->peer?->id);
        self::assertSame(80.0, $reputations->get('a')->score, 'the non-responsive peer still gets a bad event');
        self::assertSame(100.0, $reputations->get('b')->score, 'the responder gets a good event');
    }

    /** @return array{0: PeerResultAcquirer, 1: InMemoryPeerReputationRepository} */
    private function acquirer(InMemoryPeerRepository $peers, FakePeerCheckClient $client): array
    {
        $config      = new Config('data', 300, 90, 10, 240, 900, 'uphptime-test', 'UTC');
        $clock       = new FakeClock('2026-01-01T00:00:00+00:00');
        $reputations = new InMemoryPeerReputationRepository();
        $scorer      = new PeerReputationScorer($config);
        $selector    = new PeerSelector($peers, $reputations, $scorer, $clock, new FakeRandom());

        $attestationVerifier = new AttestationVerifier(new InMemoryChallengeTokenStore(), $clock);

        return [new PeerResultAcquirer($selector, $client, $reputations, $scorer, $clock, $attestationVerifier, $config->maxDelegatesPerAcquisition), $reputations];
    }

    private function peer(string $id): Peer
    {
        return new Peer($id, $id, 'https://' . $id . '.test/', true, true, null, [], [new PeerKey('k1', 'secret', true, true, true, new DateTimeImmutable('2026-01-01T00:00:00+00:00'))]);
    }

    private function site(): Site
    {
        $now = new DateTimeImmutable('2026-01-01T00:00:00+00:00');

        return new Site('a', 'a', 'https://example.test/', null, null, CheckType::Http, null, null, null, true, $now, new SiteRuntimeState(SiteState::Unknown, 0, null, null, $now, null));
    }
}

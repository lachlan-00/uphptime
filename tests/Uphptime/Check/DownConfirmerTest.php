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
use Uphptime\Check\AcquiredPeerResult;
use Uphptime\Check\AttestationVerifier;
use Uphptime\Check\CheckOutcome;
use Uphptime\Check\CheckResult;
use Uphptime\Check\DownConfirmer;
use Uphptime\Config\Config;
use Uphptime\Peer\Peer;
use Uphptime\Peer\PeerKey;
use Uphptime\Peer\PeerReputationScorer;
use Uphptime\Peer\PeerSelector;
use Uphptime\Site\ChallengeDelivery;
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

final class DownConfirmerTest extends TestCase
{
    public function testAcquisitionExhaustedFallsBackToASelfCheck(): void
    {
        $peers  = $this->peers(['b', 'c', 'd']);
        $client = FakePeerCheckClient::byPeerId(['b' => null, 'c' => null, 'd' => null]);

        $outcome = $this->confirmer($peers, $client)->confirm($this->site(), $this->primary('a'), 10.0);

        self::assertTrue($outcome->confirmed);
        self::assertSame('self', $outcome->source);
        self::assertSame(CheckOutcome::Pass, $outcome->result?->outcome);
    }

    public function testAcquisitionExhaustedWithAnErroringSelfCheckIsUnconfirmed(): void
    {
        $peers     = $this->peers(['b', 'c', 'd']);
        $client    = FakePeerCheckClient::byPeerId(['b' => null, 'c' => null, 'd' => null]);
        $config    = $this->config();
        $confirmer = new DownConfirmer($this->selector($peers, new InMemoryPeerReputationRepository()), $client, new InMemoryPeerReputationRepository(), new PeerReputationScorer($config), new FakeClock(), $config, $this->attestationVerifier(), ['http' => new FakeChecker([CheckResult::error('self check unavailable')])], $config->maxDelegatesPerAcquisition);

        self::assertFalse($confirmer->confirm($this->site(), $this->primary('a'), 10.0)->confirmed);
    }

    public function testConfirmingErrorDoesNotSettleAndTryingContinuesToAPassOrFail(): void
    {
        $peers  = $this->peers(['b', 'c']);
        $client = FakePeerCheckClient::byPeerId([
            'b' => new DelegatedCheckResult(CheckResult::error('config'), 'k1', null, 'none'),
            'c' => new DelegatedCheckResult(CheckResult::fail('timeout'), 'k1', null, 'none'),
        ]);

        $outcome = $this->confirmer($peers, $client)->confirm($this->site(), $this->primary('a'), 10.0);

        self::assertTrue($outcome->confirmed);
        self::assertSame(CheckOutcome::Fail, $outcome->result?->outcome);
        self::assertSame('peer:k1', $outcome->source);
    }

    public function testConfirmingPeerWithWrongTokenIsTreatedAsNonResponseAndTryingContinues(): void
    {
        $peers  = $this->peers(['b', 'c']);
        $client = FakePeerCheckClient::byPeerId([
            'b' => new DelegatedCheckResult(CheckResult::pass(200, 5), 'k1', ['resolved_ip' => '1.2.3.4', 'challenge_token_observed' => 'wrong-token'], 'none'),
            'c' => new DelegatedCheckResult(CheckResult::pass(200, 5), 'k1', ['resolved_ip' => '1.2.3.4', 'challenge_token_observed' => 'fixed-token'], 'none'),
        ]);
        $reputations = new InMemoryPeerReputationRepository();
        $clock       = new FakeClock('2026-01-01T00:00:00+00:00');
        $tokenStore  = new InMemoryChallengeTokenStore('fixed-token');
        $tokenStore->issue('a', $clock->now(), 300); // normally CheckRunner's job, before delegating
        $verifier = new AttestationVerifier($tokenStore, $clock);
        $config   = $this->config();

        $confirmer = new DownConfirmer($this->selector($peers, $reputations), $client, $reputations, new PeerReputationScorer($config), new FakeClock(), $config, $verifier, ['http' => new FakeChecker([CheckResult::pass()])], $config->maxDelegatesPerAcquisition);
        $outcome   = $confirmer->confirm($this->site()->withChallengeDelivery(ChallengeDelivery::WellKnown), $this->primary('a'), 10.0);

        self::assertTrue($outcome->confirmed);
        self::assertSame(CheckOutcome::Pass, $outcome->result?->outcome);
        self::assertSame('token', $outcome->attestation);
        self::assertLessThan(100.0, $reputations->get('b')->score, 'the wrong-token peer still takes a non-response-style penalty');
    }

    public function testExactlyOneUsablePeerFallsBackToASelfCheck(): void
    {
        $peers    = $this->peers([]);
        $client   = FakePeerCheckClient::failing();
        $checkers = ['http' => new FakeChecker([CheckResult::pass()])];

        $confirmer = new DownConfirmer($this->selector($peers, new InMemoryPeerReputationRepository()), $client, new InMemoryPeerReputationRepository(), new PeerReputationScorer($this->config()), new FakeClock(), $this->config(), $this->attestationVerifier(), $checkers, 3);
        $outcome   = $confirmer->confirm($this->site(), $this->primary('a'), 10.0);

        self::assertTrue($outcome->confirmed);
        self::assertSame('self', $outcome->source);
        self::assertSame(CheckOutcome::Pass, $outcome->result?->outcome);
    }

    public function testSelfNetworkFailureConfirmsPeerFailure(): void
    {
        $peers       = $this->peers([]);
        $config      = $this->config();
        $reputations = new InMemoryPeerReputationRepository();
        $confirmer   = new DownConfirmer(
            $this->selector($peers, $reputations),
            FakePeerCheckClient::failing(),
            $reputations,
            new PeerReputationScorer($config),
            new FakeClock(),
            $config,
            $this->attestationVerifier(),
            ['http' => new FakeChecker([CheckResult::fail('Network is unreachable')])],
            3,
        );

        $outcome = $confirmer->confirm($this->site(), $this->primary('a'), 10.0);

        self::assertTrue($outcome->confirmed, 'a peer already failed from its own vantage point, so a local connect failure corroborates it');
        self::assertSame(CheckOutcome::Fail, $outcome->result?->outcome);
        self::assertSame('self', $outcome->source);
    }

    public function testTwoDifferentPeersAgreeingOnFailResolvesFail(): void
    {
        $peers  = $this->peers(['b']);
        $client = FakePeerCheckClient::byPeerId(['b' => new DelegatedCheckResult(CheckResult::fail('timeout'), 'k1', null, 'none')]);

        $outcome = $this->confirmer($peers, $client)->confirm($this->site(), $this->primary('a'), 10.0);

        self::assertTrue($outcome->confirmed);
        self::assertSame(CheckOutcome::Fail, $outcome->result?->outcome);
        self::assertSame('peer:k1', $outcome->source);
    }

    public function testTwoDifferentPeersDisagreeingResolvesPass(): void
    {
        $peers  = $this->peers(['b']);
        $client = FakePeerCheckClient::byPeerId(['b' => new DelegatedCheckResult(CheckResult::pass(200, 5), 'k1', null, 'none')]);

        $outcome = $this->confirmer($peers, $client)->confirm($this->site(), $this->primary('a'), 10.0);

        self::assertTrue($outcome->confirmed);
        self::assertSame(CheckOutcome::Pass, $outcome->result?->outcome);
    }

    private function attestationVerifier(): AttestationVerifier
    {
        return new AttestationVerifier(new InMemoryChallengeTokenStore(), new FakeClock());
    }

    private function config(): Config
    {
        return new Config('data', 300, 90, 10, 240, 900, 'uphptime-test', 'UTC');
    }

    private function confirmer(InMemoryPeerRepository $peers, FakePeerCheckClient $client): DownConfirmer
    {
        $config   = $this->config();
        $checkers = ['http' => new FakeChecker([CheckResult::pass()])];

        return new DownConfirmer($this->selector($peers, new InMemoryPeerReputationRepository()), $client, new InMemoryPeerReputationRepository(), new PeerReputationScorer($config), new FakeClock(), $config, $this->attestationVerifier(), $checkers, $config->maxDelegatesPerAcquisition);
    }

    /** @param string[] $ids */
    private function peers(array $ids): InMemoryPeerRepository
    {
        $peers = new InMemoryPeerRepository();
        foreach ($ids as $id) {
            $peers->save(new Peer($id, $id, 'https://' . $id . '.test/', true, true, null, [], [new PeerKey('k1', 'secret', true, true, true, new DateTimeImmutable('2026-01-01T00:00:00+00:00'))]));
        }

        return $peers;
    }

    private function primary(string $peerId): AcquiredPeerResult
    {
        return new AcquiredPeerResult(
            new Peer($peerId, $peerId, 'https://' . $peerId . '.test/', true, true, null, [], [new PeerKey('k1', 'secret', true, true, true, new DateTimeImmutable('2026-01-01T00:00:00+00:00'))]),
            'k1',
            CheckResult::fail('timeout'),
            null,
            'none',
        );
    }

    private function selector(InMemoryPeerRepository $peers, InMemoryPeerReputationRepository $reputations): PeerSelector
    {
        return new PeerSelector($peers, $reputations, new PeerReputationScorer($this->config()), new FakeClock(), new FakeRandom());
    }

    private function site(): Site
    {
        $now = new DateTimeImmutable('2026-01-01T00:00:00+00:00');

        return new Site('a', 'a', 'https://example.test/', null, null, CheckType::Http, null, null, null, true, $now, new SiteRuntimeState(SiteState::Up, 0, null, null, $now, null));
    }
}

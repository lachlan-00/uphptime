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
use Uphptime\Check\CanarySampler;
use Uphptime\Check\CheckOutcome;
use Uphptime\Check\CheckResult;
use Uphptime\Check\PeerResultAcquirer;
use Uphptime\Config\Config;
use Uphptime\History\HistoryRecordRole;
use Uphptime\Peer\Peer;
use Uphptime\Peer\PeerKey;
use Uphptime\Peer\PeerReputationScorer;
use Uphptime\Peer\PeerSelector;
use Uphptime\Site\CheckType;
use Uphptime\Site\MaintenanceWindow;
use Uphptime\Site\Site;
use Uphptime\Site\SiteRuntimeState;
use Uphptime\Site\SiteState;
use Uphptime\Tests\Api\ChallengeToken\InMemoryChallengeTokenStore;
use Uphptime\Tests\Api\Client\FakePeerCheckClient;
use Uphptime\Tests\Storage\InMemoryPeerRepository;
use Uphptime\Tests\Storage\InMemoryPeerReputationRepository;
use Uphptime\Tests\Support\FakeClock;
use Uphptime\Tests\Support\FakeRandom;

final class CanarySamplerTest extends TestCase
{
    public function testCanaryRecordIsExcludedByMaintenanceWhenCheckedAtFallsInsideAWindow(): void
    {
        $peers  = $this->peers(['a', 'b']);
        $client = FakePeerCheckClient::byPeerId(['b' => new DelegatedCheckResult(CheckResult::pass(200, 5), 'k1', null, 'none')]);
        $site   = $this->site()->withMaintenanceWindows([
            new MaintenanceWindow(
                id: 'win1',
                startsAt: new DateTimeImmutable('2025-12-31T23:00:00+00:00'),
                endsAt: new DateTimeImmutable('2026-01-01T01:00:00+00:00'),
                note: null,
            ),
        ]);

        $outcome = $this->sampler($peers, $client, new InMemoryPeerReputationRepository(), sampled: true)
            ->sampleAfterPass($site, $this->primary('a'), SiteState::Up, new DateTimeImmutable('2026-01-01T00:00:00+00:00'));

        self::assertCount(1, $outcome->extraRecords);
        self::assertTrue($outcome->extraRecords[0]->excludedByMaintenance);
    }

    public function testDisagreementUnresolvedWithNoThirdPeerLeavesTheMildPenaltyStanding(): void
    {
        $peers       = $this->peers(['a', 'b']);
        $client      = FakePeerCheckClient::byPeerId(['b' => new DelegatedCheckResult(CheckResult::fail('mismatch'), 'k1', null, 'none')]);
        $reputations = new InMemoryPeerReputationRepository();

        $outcome = $this->sampler($peers, $client, $reputations, sampled: true)->sampleAfterPass($this->site(), $this->primary('a'), SiteState::Up, new DateTimeImmutable());

        self::assertTrue($outcome->flagged);
        self::assertCount(1, $outcome->extraRecords);
        self::assertSame(HistoryRecordRole::Canary, $outcome->extraRecords[0]->role);
    }

    public function testNotSampledByDefaultProbabilityProducesNoRecords(): void
    {
        $peers  = $this->peers(['a', 'b']);
        $client = FakePeerCheckClient::byPeerId(['b' => new DelegatedCheckResult(CheckResult::pass(200, 5), 'k1', null, 'none')]);

        $outcome = $this->sampler($peers, $client, new InMemoryPeerReputationRepository(), sampled: false)->sampleAfterPass($this->site(), $this->primary('a'), SiteState::Up, new DateTimeImmutable());

        self::assertFalse($outcome->flagged);
        self::assertSame([], $outcome->extraRecords);
    }

    public function testSampledAgreementIsNotFlagged(): void
    {
        $peers  = $this->peers(['a', 'b']);
        $client = FakePeerCheckClient::byPeerId(['b' => new DelegatedCheckResult(CheckResult::pass(200, 5), 'k1', null, 'none')]);

        $outcome = $this->sampler($peers, $client, new InMemoryPeerReputationRepository(), sampled: true)->sampleAfterPass($this->site(), $this->primary('a'), SiteState::Up, new DateTimeImmutable());

        self::assertFalse($outcome->flagged);
        self::assertCount(1, $outcome->extraRecords);
        self::assertSame(CheckOutcome::Pass, $outcome->extraRecords[0]->outcome);
    }

    public function testTieBreakConfirmsAFalsePassAndPenalizesTheOriginalHarshest(): void
    {
        $peers       = $this->peers(['a', 'b', 'c']);
        $client      = FakePeerCheckClient::byPeerId([
            'b' => new DelegatedCheckResult(CheckResult::fail('mismatch'), 'k1', null, 'none'),
            'c' => new DelegatedCheckResult(CheckResult::fail('mismatch'), 'k1', null, 'none'),
        ]);
        $reputations = new InMemoryPeerReputationRepository();

        $outcome = $this->sampler($peers, $client, $reputations, sampled: true)->sampleAfterPass($this->site(), $this->primary('a'), SiteState::Up, new DateTimeImmutable());

        self::assertTrue($outcome->flagged);
        self::assertCount(2, $outcome->extraRecords);
        self::assertSame(HistoryRecordRole::TieBreak, $outcome->extraRecords[1]->role);
        self::assertSame(CheckOutcome::Fail, $outcome->extraRecords[1]->outcome);
        // Disagreement first (100 -> 65), then the harsher false-pass event on top (65 -> 32.5).
        self::assertEqualsWithDelta(32.5, $reputations->get('a')->score, 0.01, 'the original peer takes the severe false-pass penalty on top of the earlier disagreement ding');
    }

    public function testTieBreakVindicatesTheOriginalPass(): void
    {
        $peers       = $this->peers(['a', 'b', 'c']);
        $client      = FakePeerCheckClient::byPeerId([
            'b' => new DelegatedCheckResult(CheckResult::fail('mismatch'), 'k1', null, 'none'),
            'c' => new DelegatedCheckResult(CheckResult::pass(200, 5), 'k1', null, 'none'),
        ]);
        $reputations = new InMemoryPeerReputationRepository();

        $outcome = $this->sampler($peers, $client, $reputations, sampled: true)->sampleAfterPass($this->site(), $this->primary('a'), SiteState::Up, new DateTimeImmutable());

        self::assertTrue($outcome->flagged);
        self::assertCount(2, $outcome->extraRecords);
        self::assertSame(HistoryRecordRole::TieBreak, $outcome->extraRecords[1]->role);
        self::assertSame(CheckOutcome::Pass, $outcome->extraRecords[1]->outcome);
        // Disagreement first (100 -> 65), then a good event layered on top (65 -> 72), not a reset to 100.
        self::assertEqualsWithDelta(72.0, $reputations->get('a')->score, 0.01, 'the vindicated original gets a good event layered on its earlier disagreement ding');
    }

    private function config(): Config
    {
        return new Config('data', 300, 90, 10, 240, 900, 'uphptime-test', 'UTC');
    }

    private function peer(string $id): Peer
    {
        return new Peer($id, $id, 'https://' . $id . '.test/', true, true, null, [], [new PeerKey('k1', 'secret', true, true, true, new DateTimeImmutable('2026-01-01T00:00:00+00:00'))]);
    }

    /** @param string[] $ids */
    private function peers(array $ids): InMemoryPeerRepository
    {
        $peers = new InMemoryPeerRepository();
        foreach ($ids as $id) {
            $peers->save($this->peer($id));
        }

        return $peers;
    }

    private function primary(string $peerId): AcquiredPeerResult
    {
        return new AcquiredPeerResult($this->peer($peerId), 'k1', CheckResult::pass(200, 5), null, 'none');
    }

    private function sampler(InMemoryPeerRepository $peers, FakePeerCheckClient $client, InMemoryPeerReputationRepository $reputations, bool $sampled): CanarySampler
    {
        $config              = $this->config();
        $clock               = new FakeClock('2026-01-01T00:00:00+00:00');
        $random              = new FakeRandom($sampled ? 0.0 : 1.0);
        $scorer              = new PeerReputationScorer($config);
        $selector            = new PeerSelector($peers, $reputations, $scorer, $clock, $random);
        $attestationVerifier = new AttestationVerifier(new InMemoryChallengeTokenStore(), $clock);
        $acquirer            = new PeerResultAcquirer($selector, $client, $reputations, $scorer, $clock, $attestationVerifier, $config->maxDelegatesPerAcquisition);
        $checkers            = ['http' => new FakeChecker([CheckResult::pass()])];

        return new CanarySampler($random, $selector, $acquirer, $reputations, $scorer, $clock, $config, $checkers, $config->canarySampleRate);
    }

    private function site(): Site
    {
        $now = new DateTimeImmutable('2026-01-01T00:00:00+00:00');

        return new Site('a', 'a', 'https://example.test/', null, null, CheckType::Http, null, null, null, true, $now, new SiteRuntimeState(SiteState::Up, 0, null, null, $now, null));
    }
}

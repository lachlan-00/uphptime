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
use Uphptime\Check\CanarySampler;
use Uphptime\Check\CheckOutcome;
use Uphptime\Check\CheckResult;
use Uphptime\Check\CheckRunner;
use Uphptime\Check\DownConfirmer;
use Uphptime\Check\PeerResultAcquirer;
use Uphptime\Config\Config;
use Uphptime\History\HistoryRecordRole;
use Uphptime\Peer\Peer;
use Uphptime\Peer\PeerKey;
use Uphptime\Peer\PeerReputation;
use Uphptime\Peer\PeerReputationScorer;
use Uphptime\Peer\PeerSelector;
use Uphptime\Peer\PeerSelectorInterface;
use Uphptime\Reporting\RetentionPruner;
use Uphptime\Reporting\RollupBuilder;
use Uphptime\Reporting\UptimeReportCalculator;
use Uphptime\Site\ChallengeDelivery;
use Uphptime\Site\CheckType;
use Uphptime\Site\MaintenanceWindow;
use Uphptime\Site\Site;
use Uphptime\Site\SiteRuntimeState;
use Uphptime\Site\SiteState;
use Uphptime\Site\SiteStateMachine;
use Uphptime\Tests\Api\ChallengeToken\InMemoryChallengeTokenStore;
use Uphptime\Tests\Api\Client\FakePeerCheckClient;
use Uphptime\Tests\Storage\InMemoryHistoryRepository;
use Uphptime\Tests\Storage\InMemoryPeerRepository;
use Uphptime\Tests\Storage\InMemoryPeerReputationRepository;
use Uphptime\Tests\Storage\InMemoryRollupRepository;
use Uphptime\Tests\Storage\InMemorySiteRepository;
use Uphptime\Tests\Support\FakeClock;
use Uphptime\Tests\Support\FakeRandom;

final class CheckRunnerTest extends TestCase
{
    public function testBudgetExhaustionLeavesUnreachedSitesUntouched(): void
    {
        $clock = new FakeClock('2026-01-01T00:10:00+00:00');
        $sites = new InMemorySiteRepository();
        $sites->save($this->site('a', new DateTimeImmutable('2026-01-01T00:00:00+00:00')));
        $sites->save($this->site('b', new DateTimeImmutable('2026-01-01T00:00:00+00:00')));
        $history = new InMemoryHistoryRepository();
        $checker = new FakeChecker([CheckResult::pass()]);

        $summary = $this->buildRunner($sites, $history, $clock, $checker, $this->config(runBudgetSeconds: 0))->run();

        self::assertSame(0, $summary->checked);
        self::assertSame(2, $summary->skippedDueToBudget);

        $site = $sites->find('a');
        self::assertNotNull($site);
        self::assertEquals(new DateTimeImmutable('2026-01-01T00:00:00+00:00'), $site->state->nextDueAt, 'an unreached site keeps its old next_due_at');
    }

    public function testCanaryDisagreementUnresolvedWhenNoThirdPeerExistsLeavesSiteStateUntouched(): void
    {
        $clock = new FakeClock('2026-01-01T00:00:00+00:00');
        $sites = new InMemorySiteRepository();
        $sites->save($this->site('a', new DateTimeImmutable('2026-01-01T00:00:00+00:00')));
        $history = new InMemoryHistoryRepository();
        $peers   = new InMemoryPeerRepository();
        $peers->save($this->peer('peer-a'));
        $peers->save($this->peer('peer-b'));
        $peerClient = FakePeerCheckClient::byPeerId([
            'peer-a' => new DelegatedCheckResult(CheckResult::pass(200, 10), 'k1', null, 'none'),
            'peer-b' => new DelegatedCheckResult(CheckResult::fail('mismatch'), 'k1', null, 'none'),
        ]);

        $this->buildRunner($sites, $history, $clock, new FakeChecker(), $this->config(), $peers, $peerClient, random: new FakeRandom(probability: 0.0))->run();

        $site = $sites->find('a');
        self::assertNotNull($site);
        self::assertSame(SiteState::Up, $site->state->status, 'canary/tie-break never touch site state');

        $records = $history->readDay('a', '2026-01-01');
        self::assertCount(2, $records, 'the primary pass plus the canary record; no third peer exists for a tie-break');
        self::assertSame(HistoryRecordRole::Primary, $records[0]->role);
        self::assertTrue($records[0]->flagged, 'a raised disagreement flags the primary record even when unresolved');
        self::assertSame(HistoryRecordRole::Canary, $records[1]->role);
    }

    public function testCanaryTieBreakVindicatesOriginalPass(): void
    {
        $clock = new FakeClock('2026-01-01T00:00:00+00:00');
        $sites = new InMemorySiteRepository();
        $sites->save($this->site('a', new DateTimeImmutable('2026-01-01T00:00:00+00:00')));
        $history = new InMemoryHistoryRepository();
        $peers   = new InMemoryPeerRepository();
        $peers->save($this->peer('peer-a'));
        $peers->save($this->peer('peer-b'));
        $peers->save($this->peer('peer-c'));
        $peerClient = FakePeerCheckClient::byPeerId([
            'peer-a' => new DelegatedCheckResult(CheckResult::pass(200, 10), 'k1', null, 'none'),
            'peer-b' => new DelegatedCheckResult(CheckResult::fail('mismatch'), 'k1', null, 'none'),
            'peer-c' => new DelegatedCheckResult(CheckResult::pass(200, 11), 'k1', null, 'none'),
        ]);

        $this->buildRunner($sites, $history, $clock, new FakeChecker(), $this->config(), $peers, $peerClient, random: new FakeRandom(probability: 0.0))->run();

        $site = $sites->find('a');
        self::assertNotNull($site);
        self::assertSame(SiteState::Up, $site->state->status);

        $records = $history->readDay('a', '2026-01-01');
        self::assertCount(3, $records);
        self::assertTrue($records[0]->flagged);
        self::assertSame(HistoryRecordRole::Canary, $records[1]->role);
        self::assertSame(HistoryRecordRole::TieBreak, $records[2]->role);
        self::assertSame(CheckOutcome::Pass, $records[2]->outcome);
        self::assertSame(['peer-a', 'peer-b', 'peer-c'], array_map(static fn($record) => $record->peerId, $records));
        self::assertSame(['peer-a', 'peer-b', 'peer-c'], array_map(static fn($record) => $record->peerLabel, $records));
    }

    public function testChallengeTokenMatchYieldsTokenAttestation(): void
    {
        $clock = new FakeClock('2026-01-01T00:00:00+00:00');
        $sites = new InMemorySiteRepository();
        $sites->save($this->site('a', new DateTimeImmutable('2026-01-01T00:00:00+00:00'))->withChallengeDelivery(ChallengeDelivery::WellKnown));
        $history = new InMemoryHistoryRepository();
        $peers   = new InMemoryPeerRepository();
        $peers->save($this->peer('peer-a'));
        $peerClient = FakePeerCheckClient::byPeerId([
            'peer-a' => new DelegatedCheckResult(CheckResult::pass(200, 10), 'k1', ['resolved_ip' => '1.2.3.4', 'challenge_token_observed' => 'fixed-token'], 'none'),
        ]);

        $this->buildRunner($sites, $history, $clock, new FakeChecker(), $this->config(), $peers, $peerClient, challengeTokenStore: new InMemoryChallengeTokenStore('fixed-token'))->run();

        $records = $history->readDay('a', '2026-01-01');
        self::assertCount(1, $records);
        self::assertSame(CheckOutcome::Pass, $records[0]->outcome);
        self::assertSame('token', $records[0]->attestation);
    }

    public function testChallengeTokenMismatchIsNeverAppliedDirectlyAndADifferentPeerIsTried(): void
    {
        $clock = new FakeClock('2026-01-01T00:00:00+00:00');
        $sites = new InMemorySiteRepository();
        $sites->save($this->site('a', new DateTimeImmutable('2026-01-01T00:00:00+00:00'))->withChallengeDelivery(ChallengeDelivery::WellKnown));
        $history = new InMemoryHistoryRepository();
        $peers   = new InMemoryPeerRepository();
        $peers->save($this->peer('peer-a'));
        $peers->save($this->peer('peer-b'));
        $peerClient = FakePeerCheckClient::byPeerId([
            'peer-a' => new DelegatedCheckResult(CheckResult::pass(200, 10), 'k1', ['resolved_ip' => '1.2.3.4', 'challenge_token_observed' => 'wrong-token'], 'none'),
            'peer-b' => new DelegatedCheckResult(CheckResult::pass(200, 11), 'k1', ['resolved_ip' => '1.2.3.4', 'challenge_token_observed' => 'fixed-token'], 'none'),
        ]);

        $this->buildRunner($sites, $history, $clock, new FakeChecker(), $this->config(), $peers, $peerClient, challengeTokenStore: new InMemoryChallengeTokenStore('fixed-token'))->run();

        $records = $history->readDay('a', '2026-01-01');
        self::assertCount(1, $records, 'the rejected peer-a attempt never writes a history line of its own');
        self::assertSame(CheckOutcome::Pass, $records[0]->outcome);
        self::assertSame('token', $records[0]->attestation, 'only the genuinely verified peer-b result is ever applied');
    }

    public function testConfigurationExceptionYieldsErrorOutcomeWithNoStateMutationButAHistoryLine(): void
    {
        $clock = new FakeClock('2026-01-01T00:00:00+00:00');
        $sites = new InMemorySiteRepository();
        $sites->save($this->site('a', new DateTimeImmutable('2026-01-01T00:00:00+00:00')));
        $history = new InMemoryHistoryRepository();
        $checker = new FakeChecker(throwsConfigurationError: true);

        $this->buildRunner($sites, $history, $clock, $checker, $this->config())->run();

        $site = $sites->find('a');
        self::assertNotNull($site);
        self::assertSame(SiteState::Unknown, $site->state->status, 'a configuration error must not move the state machine');
        self::assertSame(0, $site->state->consecutiveFails);

        $records = $history->readDay('a', '2026-01-01');
        self::assertCount(1, $records);
        self::assertSame(CheckOutcome::Error, $records[0]->outcome);
    }

    public function testConfirmingPeerDisagreesAndSiteReturnsToUp(): void
    {
        $clock = new FakeClock('2026-01-01T00:00:00+00:00');
        $sites = new InMemorySiteRepository();
        $sites->save($this->site('a', new DateTimeImmutable('2026-01-01T00:00:00+00:00'), SiteState::Up));
        $history = new InMemoryHistoryRepository();
        $peers   = new InMemoryPeerRepository();
        $peers->save($this->peer('peer-a'));
        $peers->save($this->peer('peer-b'));
        $peerClient = FakePeerCheckClient::byPeerId([
            'peer-a' => new DelegatedCheckResult(CheckResult::fail('timeout'), 'k1', null, 'none'),
            'peer-b' => new DelegatedCheckResult(CheckResult::pass(200, 5), 'k1', null, 'none'),
        ]);

        $this->buildRunner($sites, $history, $clock, new FakeChecker(), $this->config(), $peers, $peerClient)->run();

        $site = $sites->find('a');
        self::assertNotNull($site);
        self::assertSame(SiteState::Up, $site->state->status, 'a disagreeing confirmation keeps the site up, never suspect/down');
        self::assertSame(0, $site->state->consecutiveFails);

        $records = $history->readDay('a', '2026-01-01');
        self::assertCount(2, $records);
        self::assertSame(HistoryRecordRole::Confirmation, $records[0]->role);
        self::assertSame(CheckOutcome::Fail, $records[0]->outcome);
        self::assertSame(SiteState::Up, $records[0]->stateBefore, 'the unconfirmed primary never mutates state by itself');
        self::assertSame(SiteState::Up, $records[0]->stateAfter);
        self::assertSame(HistoryRecordRole::Primary, $records[1]->role);
        self::assertSame(CheckOutcome::Pass, $records[1]->outcome);
        self::assertSame('peer:k1', $records[1]->source);
        self::assertSame('peer-a', $records[0]->peerId);
        self::assertSame('peer-b', $records[1]->peerId);
        self::assertSame('peer-b', $records[1]->peerLabel);
        $report = (new UptimeReportCalculator($history, new InMemoryRollupRepository(), $clock))->overall('a');
        self::assertSame(1, $report->checksTotal);
        self::assertSame(100.0, $report->uptimePercent());
    }

    public function testDueSitesAreProcessedInSoonestDueFirstOrder(): void
    {
        $clock = new FakeClock('2026-01-01T00:10:00+00:00');
        $sites = new InMemorySiteRepository();
        $sites->save($this->site('later', new DateTimeImmutable('2026-01-01T00:05:00+00:00')));
        $sites->save($this->site('earlier', new DateTimeImmutable('2026-01-01T00:00:00+00:00')));
        $history = new InMemoryHistoryRepository();
        $checker = new FakeChecker([CheckResult::pass(), CheckResult::pass()]);

        $summary = $this->buildRunner($sites, $history, $clock, $checker, $this->config())->run();

        self::assertSame(2, $summary->checked);
        $earlierRecords = $history->readDay('earlier', '2026-01-01');
        $laterRecords   = $history->readDay('later', '2026-01-01');
        self::assertCount(1, $earlierRecords);
        self::assertCount(1, $laterRecords);
    }

    public function testExactlyOnePeerFallsBackToSelfForConfirmation(): void
    {
        $clock = new FakeClock('2026-01-01T00:00:00+00:00');
        $sites = new InMemorySiteRepository();
        $sites->save($this->site('a', new DateTimeImmutable('2026-01-01T00:00:00+00:00'), SiteState::Up));
        $history = new InMemoryHistoryRepository();
        $peers   = new InMemoryPeerRepository();
        $peers->save($this->peer('peer-a'));
        $peerClient = FakePeerCheckClient::byPeerId([
            'peer-a' => new DelegatedCheckResult(CheckResult::fail('timeout'), 'k1', null, 'none'),
        ]);

        $this->buildRunner($sites, $history, $clock, new FakeChecker([CheckResult::pass()]), $this->config(), $peers, $peerClient)->run();

        $records = $history->readDay('a', '2026-01-01');
        self::assertCount(2, $records);
        self::assertSame(HistoryRecordRole::Primary, $records[1]->role);
        self::assertSame('self', $records[1]->source, 'with no different peer to ask, the confirmer is a self-check');
        self::assertSame('peer-a', $records[0]->peerId);
        self::assertNull($records[1]->peerId);
        self::assertNull($records[1]->peerLabel);
    }

    public function testExcludedByMaintenanceFlagIsSetWhenCheckFallsInsideAnActiveWindow(): void
    {
        $clock = new FakeClock('2026-01-01T00:00:00+00:00');
        $sites = new InMemorySiteRepository();
        $site  = $this->site('a', new DateTimeImmutable('2026-01-01T00:00:00+00:00'));
        $sites->save($site->withMaintenanceWindows([
            new MaintenanceWindow(
                id: 'win1',
                startsAt: new DateTimeImmutable('2025-12-31T23:00:00+00:00'),
                endsAt: new DateTimeImmutable('2026-01-01T01:00:00+00:00'),
                note: null,
            ),
        ]));
        $history = new InMemoryHistoryRepository();
        $checker = new FakeChecker([CheckResult::pass()]);

        $this->buildRunner($sites, $history, $clock, $checker, $this->config())->run();

        $records = $history->readDay('a', '2026-01-01');
        self::assertCount(1, $records);
        self::assertTrue($records[0]->excludedByMaintenance);
    }

    public function testExcludedPeersDoNotMakeLocalNetworkFailuresCountAsDowntime(): void
    {
        $clock = new FakeClock('2026-01-01T00:00:00+00:00');
        $sites = new InMemorySiteRepository();
        $sites->save($this->site('a', $clock->now(), SiteState::Up));
        $history = new InMemoryHistoryRepository();
        $peers   = new InMemoryPeerRepository();
        $peers->save($this->peer('peer-a'));
        $reputations = new InMemoryPeerReputationRepository();
        $reputations->save(PeerReputation::neutral('peer-a')->withExclusion($clock->now()->modify('+1 hour'), 1));

        $this->buildRunner($sites, $history, $clock, new FakeChecker([CheckResult::fail('No route to host')]), $this->config(), $peers, FakePeerCheckClient::failing(), $reputations)->run();

        self::assertSame(CheckOutcome::Error, $history->readDay('a', '2026-01-01')[0]->outcome);
        self::assertSame(SiteState::Unknown, $sites->find('a')->state->effectiveStatus($clock->now(), 900));
    }

    public function testFirstPeerFailSecondDifferentPeerConfirmsFailMovesTowardDown(): void
    {
        $clock = new FakeClock('2026-01-01T00:00:00+00:00');
        $sites = new InMemorySiteRepository();
        $sites->save($this->site('a', new DateTimeImmutable('2026-01-01T00:00:00+00:00'), SiteState::Up));
        $history = new InMemoryHistoryRepository();
        $peers   = new InMemoryPeerRepository();
        $peers->save($this->peer('peer-a'));
        $peers->save($this->peer('peer-b'));
        $peerClient = FakePeerCheckClient::byPeerId([
            'peer-a' => new DelegatedCheckResult(CheckResult::fail('timeout'), 'k1', null, 'none'),
            'peer-b' => new DelegatedCheckResult(CheckResult::fail('timeout'), 'k1', null, 'none'),
        ]);

        $this->buildRunner($sites, $history, $clock, new FakeChecker(), $this->config(), $peers, $peerClient)->run();

        $site = $sites->find('a');
        self::assertNotNull($site);
        self::assertSame(SiteState::Suspect, $site->state->status, 'two independently-confirmed fails move up -> suspect, same as the unmodified table');
        self::assertSame(1, $site->state->consecutiveFails);

        $records = $history->readDay('a', '2026-01-01');
        self::assertCount(2, $records);
        self::assertSame(HistoryRecordRole::Primary, $records[0]->role);
        self::assertSame(HistoryRecordRole::Confirmation, $records[1]->role);
        self::assertSame(SiteState::Suspect, $records[1]->stateAfter);
    }

    public function testLocalHttpErrorResponseStillCountsAsFailure(): void
    {
        $clock = new FakeClock('2026-01-01T00:00:00+00:00');
        $sites = new InMemorySiteRepository();
        $sites->save($this->site('a', $clock->now(), SiteState::Up));
        $history = new InMemoryHistoryRepository();
        $this->buildRunner($sites, $history, $clock, new FakeChecker([CheckResult::fail('bad_status', 503, 5)]), $this->config())->run();

        self::assertSame(CheckOutcome::Fail, $history->readDay('a', '2026-01-01')[0]->outcome);
        self::assertSame(SiteState::Suspect, $sites->find('a')->state->status);
    }

    public function testNextDueAtIsAlwaysCheckedAtPlusIntervalNeverBacklogIncremented(): void
    {
        $clock = new FakeClock('2026-01-01T02:00:00+00:00');
        $sites = new InMemorySiteRepository();
        // A site that was due at 00:00 but missed several 5-minute ticks (cron was skipped for 2 hours).
        $sites->save($this->site('a', new DateTimeImmutable('2026-01-01T00:00:00+00:00')));
        $history = new InMemoryHistoryRepository();
        $checker = new FakeChecker([CheckResult::pass()]);

        $this->buildRunner($sites, $history, $clock, $checker, $this->config(intervalSeconds: 300))->run();

        $site = $sites->find('a');
        self::assertNotNull($site);
        self::assertEquals(
            new DateTimeImmutable('2026-01-01T02:05:00+00:00'),
            $site->state->nextDueAt,
            'next_due_at must be checked-at + interval, not a replay of every missed slot',
        );
    }

    public function testPeerFailureWithUnreachableSelfConfirmationRemainsUnknown(): void
    {
        $clock = new FakeClock('2026-01-01T00:00:00+00:00');
        $sites = new InMemorySiteRepository();
        $sites->save($this->site('a', $clock->now(), SiteState::Up));
        $history = new InMemoryHistoryRepository();
        $peers   = new InMemoryPeerRepository();
        $peers->save($this->peer('peer-a'));
        $peerClient = FakePeerCheckClient::returning(new DelegatedCheckResult(CheckResult::fail('timeout'), 'k1', null, 'none'));

        $this->buildRunner($sites, $history, $clock, new FakeChecker([CheckResult::fail('Network is unreachable')]), $this->config(), $peers, $peerClient)->run();

        $records = $history->readDay('a', '2026-01-01');
        self::assertCount(1, $records);
        self::assertSame(CheckOutcome::Error, $records[0]->outcome);
        self::assertSame(SiteState::Unknown, $sites->find('a')->state->effectiveStatus($clock->now(), 900));
    }

    public function testPeerSourcedPassIsRecordedWithSourceKeyIdThroughTheUnmodifiedStateMachine(): void
    {
        $clock = new FakeClock('2026-01-01T00:00:00+00:00');
        $sites = new InMemorySiteRepository();
        $sites->save($this->site('a', new DateTimeImmutable('2026-01-01T00:00:00+00:00')));
        $history = new InMemoryHistoryRepository();
        $peers   = new InMemoryPeerRepository();
        $peers->save($this->peer('peer-a'));
        $peerClient = FakePeerCheckClient::returning(new DelegatedCheckResult(CheckResult::pass(200, 10), 'k1', ['resolved_ip' => '1.2.3.4'], 'none'));

        $this->buildRunner($sites, $history, $clock, new FakeChecker(), $this->config(), $peers, $peerClient)->run();

        $site = $sites->find('a');
        self::assertNotNull($site);
        self::assertSame(SiteState::Up, $site->state->status);

        $records = $history->readDay('a', '2026-01-01');
        self::assertCount(1, $records);
        self::assertSame('peer:k1', $records[0]->source);
        self::assertSame('peer-a', $records[0]->peerId);
        self::assertSame('peer-a', $records[0]->peerLabel);
        self::assertSame(['resolved_ip' => '1.2.3.4'], $records[0]->evidence);
        self::assertSame(HistoryRecordRole::Primary, $records[0]->role);
        self::assertFalse($records[0]->flagged, 'the default FakeRandom never samples, so no canary runs');
    }

    public function testSelfNetworkFailureIsUnknownAndRecoveryDoesNotCountAsDowntime(): void
    {
        $clock = new FakeClock('2026-01-01T00:00:00+00:00');
        $sites = new InMemorySiteRepository();
        $sites->save($this->site('a', $clock->now(), SiteState::Up));
        $history = new InMemoryHistoryRepository();
        $checker = new FakeChecker([CheckResult::fail('Connection timed out'), CheckResult::pass(200, 1)]);
        $runner  = $this->buildRunner($sites, $history, $clock, $checker, $this->config());
        $runner->run();

        $state = $sites->find('a')->state;
        self::assertSame(SiteState::Up, $state->status);
        self::assertSame(SiteState::Unknown, $state->effectiveStatus($clock->now(), 900));
        self::assertSame(0, $state->consecutiveFails);
        self::assertSame(CheckOutcome::Error, $history->readDay('a', '2026-01-01')[0]->outcome);
        $report = (new UptimeReportCalculator($history, new InMemoryRollupRepository(), $clock))->overall('a');
        self::assertSame(1, $report->checksUnknown);
        self::assertNull($report->uptimePercent());

        $clock->advance('+5 minutes');
        $runner->run();
        self::assertSame(SiteState::Up, $sites->find('a')->state->effectiveStatus($clock->now(), 900));
        self::assertSame(CheckOutcome::Pass, $history->readDay('a', '2026-01-01')[1]->outcome);
        $report = (new UptimeReportCalculator($history, new InMemoryRollupRepository(), $clock))->overall('a');
        self::assertSame(1, $report->checksUnknown);
        self::assertSame(100.0, $report->uptimePercent());

        $rollups = new InMemoryRollupRepository();
        $clock->advance('+1 day');
        (new RollupBuilder($sites, $history, $rollups, $clock))->closeFinishedDays();
        $rollup = $rollups->read('a', '2026-01-01');
        self::assertNotNull($rollup);
        self::assertSame(1, $rollup->checksUnknown);
        self::assertSame(0, $rollup->checksFail);
        self::assertSame(100.0, $rollup->uptimePercent);
    }

    public function testThreePeerCapAppliesIndependentlyToConfirmationAcquisition(): void
    {
        $clock = new FakeClock('2026-01-01T00:00:00+00:00');
        $sites = new InMemorySiteRepository();
        $sites->save($this->site('a', new DateTimeImmutable('2026-01-01T00:00:00+00:00'), SiteState::Up));
        $history = new InMemoryHistoryRepository();
        $peers   = new InMemoryPeerRepository();
        $peers->save($this->peer('peer-a'));
        $peers->save($this->peer('peer-b'));
        $peers->save($this->peer('peer-c'));
        $peers->save($this->peer('peer-d'));
        $peerClient = FakePeerCheckClient::byPeerId([
            'peer-a' => new DelegatedCheckResult(CheckResult::fail('timeout'), 'k1', null, 'none'),
            'peer-b' => null,
            'peer-c' => null,
            'peer-d' => null,
        ]);

        $this->buildRunner($sites, $history, $clock, new FakeChecker(), $this->config(), $peers, $peerClient)->run();

        $site = $sites->find('a');
        self::assertNotNull($site);
        self::assertSame(SiteState::Up, $site->state->status);
        self::assertSame(SiteState::Unknown, $site->state->effectiveStatus($clock->now(), 900));
        self::assertSame(0, $site->state->consecutiveFails);

        $records = $history->readDay('a', '2026-01-01');
        self::assertCount(1, $records);
        self::assertSame(HistoryRecordRole::Primary, $records[0]->role);
        self::assertSame(CheckOutcome::Error, $records[0]->outcome);
        self::assertSame('peer_failure_unconfirmed: timeout', $records[0]->error);
        self::assertSame(SiteState::Up, $records[0]->stateAfter);
        $report = (new UptimeReportCalculator($history, new InMemoryRollupRepository(), $clock))->overall('a');
        self::assertSame(1, $report->checksUnknown);
        self::assertNull($report->uptimePercent());
    }

    public function testUnresponsivePeerLeavesStateAndCounterUnchangedButWritesAnErrorHistoryLine(): void
    {
        $clock = new FakeClock('2026-01-01T00:00:00+00:00');
        $sites = new InMemorySiteRepository();
        $sites->save($this->site('a', new DateTimeImmutable('2026-01-01T00:00:00+00:00')));
        $history = new InMemoryHistoryRepository();
        $peers   = new InMemoryPeerRepository();
        $peers->save($this->peer('peer-a'));
        $peerClient = FakePeerCheckClient::failing();

        $this->buildRunner($sites, $history, $clock, new FakeChecker(), $this->config(), $peers, $peerClient)->run();

        $site = $sites->find('a');
        self::assertNotNull($site);
        self::assertSame(SiteState::Unknown, $site->state->status, 'an unresponsive peer must never mutate the state machine');
        self::assertSame(0, $site->state->consecutiveFails);

        $records = $history->readDay('a', '2026-01-01');
        self::assertCount(1, $records);
        self::assertSame(CheckOutcome::Error, $records[0]->outcome);
        self::assertStringStartsWith('peer:', $records[0]->source);
    }

    public function testZeroEnabledPeersBehavesIdenticallyToTheSelfCheckPath(): void
    {
        $clock = new FakeClock('2026-01-01T00:00:00+00:00');
        $sites = new InMemorySiteRepository();
        $sites->save($this->site('a', new DateTimeImmutable('2026-01-01T00:00:00+00:00')));
        $history = new InMemoryHistoryRepository();
        $peers   = new InMemoryPeerRepository();
        $checker = new FakeChecker([CheckResult::pass()]);

        $this->buildRunner($sites, $history, $clock, $checker, $this->config(), $peers, FakePeerCheckClient::failing())->run();

        $records = $history->readDay('a', '2026-01-01');
        self::assertCount(1, $records);
        self::assertSame('self', $records[0]->source, 'no enabled peers must fall back to the self-check path, not call the peer client');
    }

    /** @return array{0: ?PeerSelectorInterface, 1: ?PeerResultAcquirer, 2: ?DownConfirmer, 3: ?CanarySampler} */
    private function buildPeerStack(
        ?InMemoryPeerRepository $peers,
        ?FakePeerCheckClient $peerClient,
        Config $config,
        FakeClock $clock,
        ?InMemoryPeerReputationRepository $reputations,
        ?FakeRandom $random,
        InMemoryChallengeTokenStore $challengeTokenStore,
        FakeChecker $httpChecker,
    ): array {
        if ($peers === null || $peerClient === null) {
            return [null, null, null, null];
        }

        $reputations ??= new InMemoryPeerReputationRepository();
        $random ??= new FakeRandom();
        $scorer      = new PeerReputationScorer($config);
        $selector    = new PeerSelector($peers, $reputations, $scorer, $clock, $random);
        $attestation = new AttestationVerifier($challengeTokenStore, $clock);
        $acquirer    = new PeerResultAcquirer($selector, $peerClient, $reputations, $scorer, $clock, $attestation, $config->maxDelegatesPerAcquisition);

        /** @var array<string, \Uphptime\Check\CheckerInterface> $selfCheckers */
        $selfCheckers = [
            CheckType::Http->value => $httpChecker,
            CheckType::HttpKeyword->value => new FakeChecker([CheckResult::pass()]),
            CheckType::Tcp->value => new FakeChecker([CheckResult::pass()]),
        ];

        $confirmer = new DownConfirmer($selector, $peerClient, $reputations, $scorer, $clock, $config, $attestation, $selfCheckers, $config->maxDelegatesPerAcquisition);
        $canary    = new CanarySampler($random, $selector, $acquirer, $reputations, $scorer, $clock, $config, $selfCheckers, $config->canarySampleRate);

        return [$selector, $acquirer, $confirmer, $canary];
    }

    private function buildRunner(
        InMemorySiteRepository $sites,
        InMemoryHistoryRepository $history,
        FakeClock $clock,
        FakeChecker $httpChecker,
        Config $config,
        ?InMemoryPeerRepository $peers = null,
        ?FakePeerCheckClient $peerClient = null,
        ?InMemoryPeerReputationRepository $reputations = null,
        ?FakeRandom $random = null,
        ?InMemoryChallengeTokenStore $challengeTokenStore = null,
    ): CheckRunner {
        $rollups             = new InMemoryRollupRepository();
        $challengeTokenStore ??= new InMemoryChallengeTokenStore();

        [$peerSelector, $acquirer, $confirmer, $canary] = $this->buildPeerStack($peers, $peerClient, $config, $clock, $reputations, $random, $challengeTokenStore, $httpChecker);

        return new CheckRunner(
            $sites,
            $history,
            new SiteStateMachine(),
            new RollupBuilder($sites, $history, $rollups, $clock),
            new RetentionPruner($sites, $history, $rollups, $clock),
            $clock,
            $config,
            $challengeTokenStore,
            $httpChecker,
            new FakeChecker(),
            new FakeChecker(),
            new FakeChecker(),
            $peerSelector,
            $acquirer,
            $confirmer,
            $canary,
        );
    }

    private function config(int $intervalSeconds = 300, int $runBudgetSeconds = 240): Config
    {
        return new Config('data', $intervalSeconds, 90, 10, $runBudgetSeconds, 900, 'uphptime-test', 'UTC');
    }

    private function peer(string $id): Peer
    {
        return new Peer(
            $id,
            $id,
            'https://' . $id . '.test/',
            true,
            true,
            null,
            [],
            [new PeerKey('k1', 'secret', true, true, true, new DateTimeImmutable('2026-01-01T00:00:00+00:00'))],
        );
    }

    private function site(string $id, DateTimeImmutable $nextDueAt, SiteState $status = SiteState::Unknown): Site
    {
        $created = new DateTimeImmutable('2026-01-01T00:00:00+00:00');

        return new Site(
            $id,
            $id,
            'https://example.test/',
            null,
            null,
            CheckType::Http,
            null,
            null,
            null,
            true,
            $created,
            new SiteRuntimeState($status, 0, null, null, $nextDueAt, null),
        );
    }
}

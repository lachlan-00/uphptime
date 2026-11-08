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
use Uphptime\Peer\PeerReputation;
use Uphptime\Peer\PeerReputationScorer;

final class PeerReputationScorerTest extends TestCase
{
    public function testAnUnscoredPeerStartsNeutral(): void
    {
        $reputation = PeerReputation::neutral('p');

        self::assertSame(100.0, $reputation->score);
        self::assertFalse($reputation->isExcluded(new DateTimeImmutable('2026-01-01T00:00:00+00:00')));
    }

    public function testConfirmationDisagreementAloneNeverCrossesTheExclusionThreshold(): void
    {
        $scorer     = new PeerReputationScorer($this->config());
        $reputation = PeerReputation::neutral('p');
        $now        = new DateTimeImmutable('2026-01-01T00:00:00+00:00');

        for ($i = 0; $i < 200; $i++) {
            $reputation = $scorer->recordConfirmationDisagreement($reputation, $now);
        }

        self::assertEqualsWithDelta(60.0, $reputation->score, 0.01, 'converges toward its target of 60, well above the exclusion threshold');
        self::assertFalse($reputation->isExcluded($now));
    }

    public function testCooldownBackoffLengthensOnARelapse(): void
    {
        $scorer     = new PeerReputationScorer($this->config());
        $reputation = PeerReputation::neutral('p');
        $t0         = new DateTimeImmutable('2026-01-01T00:00:00+00:00');

        for ($i = 0; $i < 5; $i++) {
            $reputation = $scorer->recordNonResponse($reputation, $t0);
        }

        self::assertTrue($reputation->isExcluded($t0));
        self::assertEquals($t0->modify('+3600 seconds'), $reputation->excludedUntil);

        $t1         = $t0->modify('+3601 seconds');
        $reputation = $scorer->recordNonResponse($reputation, $t1);

        self::assertTrue($reputation->isExcluded($t1));
        self::assertEquals($t1->modify('+7200 seconds'), $reputation->excludedUntil, 'a relapse doubles the cooldown via the configured backoff multiplier');
    }

    public function testFalsePassIsTheHarshestEventAndExcludesFastest(): void
    {
        $scorer     = new PeerReputationScorer($this->config());
        $reputation = PeerReputation::neutral('p');
        $now        = new DateTimeImmutable('2026-01-01T00:00:00+00:00');

        $reputation = $scorer->recordFalsePass($reputation, $now);
        self::assertEqualsWithDelta(50.0, $reputation->score, 0.01);
        self::assertFalse($reputation->isExcluded($now));

        $reputation = $scorer->recordFalsePass($reputation, $now);
        self::assertEqualsWithDelta(25.0, $reputation->score, 0.01);
        self::assertTrue($reputation->isExcluded($now), 'two false-pass events are enough to cross the threshold, the fastest of any event type');
    }

    public function testFiveConsecutiveNonResponsesExcludeThePeer(): void
    {
        $scorer     = new PeerReputationScorer($this->config());
        $reputation = PeerReputation::neutral('p');
        $now        = new DateTimeImmutable('2026-01-01T00:00:00+00:00');

        for ($i = 0; $i < 4; $i++) {
            $reputation = $scorer->recordNonResponse($reputation, $now);
            self::assertFalse($reputation->isExcluded($now), sprintf('must not be excluded after only %d bad events', $i + 1));
        }

        $reputation = $scorer->recordNonResponse($reputation, $now);

        self::assertEqualsWithDelta(32.768, $reputation->score, 0.001);
        self::assertTrue($reputation->isExcluded($now));
        self::assertEquals($now->modify('+3600 seconds'), $reputation->excludedUntil);
        self::assertSame(1, $reputation->consecutiveExclusions);
    }

    public function testGoodResponseFromNeutralStaysAtTheCeiling(): void
    {
        $scorer     = new PeerReputationScorer($this->config());
        $reputation = $scorer->recordGoodResponse(PeerReputation::neutral('p'), new DateTimeImmutable('2026-01-01T00:00:00+00:00'));

        self::assertSame(100.0, $reputation->score);
    }

    public function testRecoveryAfterCooldownLandsOnProbationNotNeutral(): void
    {
        $scorer     = new PeerReputationScorer($this->config());
        $reputation = PeerReputation::neutral('p');
        $t0         = new DateTimeImmutable('2026-01-01T00:00:00+00:00');

        for ($i = 0; $i < 5; $i++) {
            $reputation = $scorer->recordNonResponse($reputation, $t0);
        }

        self::assertTrue($reputation->isExcluded($t0));

        $afterCooldown = $t0->modify('+3601 seconds');
        $recovered     = $scorer->recordGoodResponse($reputation, $afterCooldown);

        self::assertEqualsWithDelta(56.0, $recovered->score, 0.01, '45 (probation) + 0.2 * (100 - 45), not 100 + 0.2 * (100 - 100)');
        self::assertFalse($recovered->isExcluded($afterCooldown));
    }

    public function testRecoveryFollowedImmediatelyByAnotherBadEventReExcludesQuickly(): void
    {
        $scorer     = new PeerReputationScorer($this->config());
        $reputation = PeerReputation::neutral('p');
        $t0         = new DateTimeImmutable('2026-01-01T00:00:00+00:00');

        for ($i = 0; $i < 5; $i++) {
            $reputation = $scorer->recordNonResponse($reputation, $t0);
        }

        $afterCooldown = $t0->modify('+3601 seconds');
        $relapsed      = $scorer->recordNonResponse($reputation, $afterCooldown);

        self::assertEqualsWithDelta(36.0, $relapsed->score, 0.01, '45 (probation) + 0.2 * (0 - 45)');
        self::assertTrue($relapsed->isExcluded($afterCooldown));
    }

    private function config(): Config
    {
        return new Config('data', 300, 90, 10, 240, 900, 'uphptime-test', 'UTC');
    }
}

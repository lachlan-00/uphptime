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

namespace Uphptime\Tests\Check;

use DateTimeImmutable;
use PHPUnit\Framework\TestCase;
use Uphptime\Check\AttestationVerifier;
use Uphptime\Check\CheckResult;
use Uphptime\Site\ChallengeDelivery;
use Uphptime\Site\CheckType;
use Uphptime\Site\Site;
use Uphptime\Site\SiteRuntimeState;
use Uphptime\Site\SiteState;
use Uphptime\Tests\Api\ChallengeToken\InMemoryChallengeTokenStore;
use Uphptime\Tests\Support\FakeClock;

final class AttestationVerifierTest extends TestCase
{
    public function testFailOutcomeIsAlwaysValidRegardlessOfTokenConfiguration(): void
    {
        $clock    = new FakeClock('2026-01-01T00:00:00+00:00');
        $tokens   = new InMemoryChallengeTokenStore();
        $verifier = new AttestationVerifier($tokens, $clock);
        $tokens->issue('site-a', $clock->now(), 300);

        $verdict = $verifier->verify($this->site(ChallengeDelivery::Header), CheckResult::fail('timeout'), ['resolved_ip' => '1.2.3.4', 'challenge_token_observed' => 'wrong']);

        self::assertTrue($verdict->isValid);
        self::assertSame('evidence', $verdict->attestation);
    }

    public function testMatchingTokenOnAPassIsValidWithTokenAttestation(): void
    {
        $clock    = new FakeClock('2026-01-01T00:00:00+00:00');
        $tokens   = new InMemoryChallengeTokenStore();
        $verifier = new AttestationVerifier($tokens, $clock);
        $issued   = $tokens->issue('site-a', $clock->now(), 300);

        $verdict = $verifier->verify($this->site(ChallengeDelivery::WellKnown), CheckResult::pass(200, 5), ['resolved_ip' => '1.2.3.4', 'challenge_token_observed' => $issued->token]);

        self::assertTrue($verdict->isValid);
        self::assertSame('token', $verdict->attestation);
    }

    public function testMissingTokenOnAPassIsInvalid(): void
    {
        $clock    = new FakeClock('2026-01-01T00:00:00+00:00');
        $tokens   = new InMemoryChallengeTokenStore();
        $verifier = new AttestationVerifier($tokens, $clock);
        $tokens->issue('site-a', $clock->now(), 300);

        $verdict = $verifier->verify($this->site(ChallengeDelivery::Header), CheckResult::pass(200, 5), ['resolved_ip' => '1.2.3.4', 'challenge_token_observed' => null]);

        self::assertFalse($verdict->isValid);
    }

    public function testNoChallengeDeliveryConfiguredIsValidWithEvidenceAttestation(): void
    {
        $verifier = new AttestationVerifier(new InMemoryChallengeTokenStore(), new FakeClock());

        $verdict = $verifier->verify($this->site(null), CheckResult::pass(200, 5), ['resolved_ip' => '1.2.3.4']);

        self::assertTrue($verdict->isValid);
        self::assertSame('evidence', $verdict->attestation);
    }

    public function testNoEvidenceAtAllIsValidWithNoneAttestation(): void
    {
        $verifier = new AttestationVerifier(new InMemoryChallengeTokenStore(), new FakeClock());

        $verdict = $verifier->verify($this->site(null), CheckResult::pass(), null);

        self::assertTrue($verdict->isValid);
        self::assertSame('none', $verdict->attestation);
    }

    public function testWrongTokenOnAPassIsInvalid(): void
    {
        $clock    = new FakeClock('2026-01-01T00:00:00+00:00');
        $tokens   = new InMemoryChallengeTokenStore();
        $verifier = new AttestationVerifier($tokens, $clock);
        $tokens->issue('site-a', $clock->now(), 300);

        $verdict = $verifier->verify($this->site(ChallengeDelivery::Header), CheckResult::pass(200, 5), ['resolved_ip' => '1.2.3.4', 'challenge_token_observed' => 'wrong-token']);

        self::assertFalse($verdict->isValid);
    }

    private function site(?ChallengeDelivery $challengeDelivery): Site
    {
        $now = new DateTimeImmutable('2026-01-01T00:00:00+00:00');

        return new Site(
            id: 'site-a',
            name: 'Example',
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
            challengeDelivery: $challengeDelivery,
        );
    }
}

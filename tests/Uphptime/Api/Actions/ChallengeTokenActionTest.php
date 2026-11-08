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

namespace Uphptime\Tests\Api\Actions;

use DateTimeImmutable;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ServerRequestInterface;
use Slim\Psr7\Factory\ResponseFactory;
use Slim\Psr7\Factory\ServerRequestFactory;
use Uphptime\Api\Actions\ChallengeTokenAction;
use Uphptime\Config\Config;
use Uphptime\Site\ChallengeDelivery;
use Uphptime\Site\CheckType;
use Uphptime\Site\Site;
use Uphptime\Site\SiteRuntimeState;
use Uphptime\Site\SiteState;
use Uphptime\Tests\Api\ChallengeToken\InMemoryChallengeTokenStore;
use Uphptime\Tests\Storage\InMemorySiteRepository;
use Uphptime\Tests\Support\FakeClock;

final class ChallengeTokenActionTest extends TestCase
{
    public function testConfiguredSiteReturnsTheCurrentlyIssuedToken(): void
    {
        $sites  = new InMemorySiteRepository();
        $sites->save($this->site('site-a', ChallengeDelivery::WellKnown));
        $tokens = new InMemoryChallengeTokenStore();
        $issued = $tokens->issue('site-a', new DateTimeImmutable('2026-01-01T00:00:00+00:00'), 300);

        $response = $this->action($sites, $tokens)->handle($this->requestFor('site-a'));
        $body     = json_decode((string) $response->getBody(), true);

        self::assertSame(200, $response->getStatusCode());
        self::assertSame($issued->token, $body['token']);
    }

    public function testConfiguredSiteWithNoCurrentTokenLazilyIssuesOne(): void
    {
        $sites = new InMemorySiteRepository();
        $sites->save($this->site('site-a', ChallengeDelivery::Header));
        $action = $this->action($sites, new InMemoryChallengeTokenStore());

        $response = $action->handle($this->requestFor('site-a'));
        $body     = json_decode((string) $response->getBody(), true);

        self::assertSame(200, $response->getStatusCode());
        self::assertIsString($body['token']);
        self::assertNotSame('', $body['token']);
    }

    public function testSiteWithNoChallengeDeliveryConfiguredIs404(): void
    {
        $sites = new InMemorySiteRepository();
        $sites->save($this->site('site-a', null));

        $response = $this->action($sites, new InMemoryChallengeTokenStore())->handle($this->requestFor('site-a'));

        self::assertSame(404, $response->getStatusCode());
    }

    public function testUnknownSiteIs404(): void
    {
        $response = $this->action(new InMemorySiteRepository(), new InMemoryChallengeTokenStore())->handle($this->requestFor('missing'));

        self::assertSame(404, $response->getStatusCode());
    }

    private function action(InMemorySiteRepository $sites, InMemoryChallengeTokenStore $tokens): ChallengeTokenAction
    {
        return new ChallengeTokenAction(
            $sites,
            $tokens,
            new FakeClock('2026-01-01T00:00:00+00:00'),
            new Config('data', 300, 90, 10, 240, 900, 'uphptime-test', 'UTC'),
            new ResponseFactory(),
        );
    }

    private function requestFor(string $siteId): ServerRequestInterface
    {
        $request = (new ServerRequestFactory())->createServerRequest('GET', '/challenge/v1/' . $siteId);

        return $request->withAttribute('uphptime.site_id', $siteId);
    }

    private function site(string $id, ?ChallengeDelivery $challengeDelivery): Site
    {
        $now = new DateTimeImmutable('2026-01-01T00:00:00+00:00');

        return new Site(
            id: $id,
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

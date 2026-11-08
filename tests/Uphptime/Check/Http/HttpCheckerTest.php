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

namespace Uphptime\Tests\Check\Http;

use DateTimeImmutable;
use PHPUnit\Framework\TestCase;
use Uphptime\Check\CheckConfigurationException;
use Uphptime\Check\CheckOutcome;
use Uphptime\Check\Http\HttpChecker;
use Uphptime\Check\Http\HttpResponse;
use Uphptime\Site\CheckType;
use Uphptime\Site\Site;
use Uphptime\Site\SiteRuntimeState;
use Uphptime\Site\SiteState;

final class HttpCheckerTest extends TestCase
{
    public function test2xxStatusPasses(): void
    {
        $client = FakeHttpClient::returning(new HttpResponse(200, 'ok', 42));
        $result = (new HttpChecker($client, 'uphptime-test'))->check($this->site('https://example.test/'), 5.0);

        self::assertSame(CheckOutcome::Pass, $result->outcome);
        self::assertSame(200, $result->httpStatus);
        self::assertSame(42, $result->responseTimeMs);
    }

    public function test3xxRedirectPasses(): void
    {
        $client = FakeHttpClient::returning(new HttpResponse(302, 'redirecting to sso', 15));
        $result = (new HttpChecker($client, 'uphptime-test'))->check($this->site('https://example.test/'), 5.0);

        self::assertSame(CheckOutcome::Pass, $result->outcome);
        self::assertSame(302, $result->httpStatus);
    }

    public function test5xxStatusFails(): void
    {
        $client = FakeHttpClient::returning(new HttpResponse(503, 'down', 10));
        $result = (new HttpChecker($client, 'uphptime-test'))->check($this->site('https://example.test/'), 5.0);

        self::assertSame(CheckOutcome::Fail, $result->outcome);
        self::assertSame('bad_status', $result->error);
    }

    public function testConnectionErrorFails(): void
    {
        $client = FakeHttpClient::failingWith('Connection refused');
        $result = (new HttpChecker($client, 'uphptime-test'))->check($this->site('https://example.test/'), 5.0);

        self::assertSame(CheckOutcome::Fail, $result->outcome);
        self::assertSame('Connection refused', $result->error);
    }

    public function testMissingUrlThrowsConfigurationException(): void
    {
        $client = FakeHttpClient::returning(new HttpResponse(200, 'ok', 1));

        $this->expectException(CheckConfigurationException::class);
        (new HttpChecker($client, 'uphptime-test'))->check($this->site(null), 5.0);
    }

    private function site(?string $url): Site
    {
        return new Site(
            id: 'site-1',
            name: 'Example',
            url: $url,
            host: null,
            port: null,
            checkType: CheckType::Http,
            keyword: null,
            keywordMode: null,
            checkIntervalSeconds: null,
            enabled: true,
            createdAt: new DateTimeImmutable('2026-01-01T00:00:00+00:00'),
            state: new SiteRuntimeState(SiteState::Unknown, 0, null, null, new DateTimeImmutable('2026-01-01T00:00:00+00:00'), null),
        );
    }
}

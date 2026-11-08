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
use Uphptime\Check\CheckOutcome;
use Uphptime\Check\Http\HttpKeywordChecker;
use Uphptime\Check\Http\HttpResponse;
use Uphptime\Site\CheckType;
use Uphptime\Site\KeywordMode;
use Uphptime\Site\Site;
use Uphptime\Site\SiteRuntimeState;
use Uphptime\Site\SiteState;

final class HttpKeywordCheckerTest extends TestCase
{
    public function testContainsModeFailsWhenKeywordAbsent(): void
    {
        $client = FakeHttpClient::returning(new HttpResponse(200, 'nothing here', 1));
        $result = (new HttpKeywordChecker($client, 'uphptime-test'))->check($this->site(KeywordMode::Contains, 'home'), 5.0);

        self::assertSame(CheckOutcome::Fail, $result->outcome);
        self::assertSame('keyword_mismatch', $result->error);
        self::assertFalse($result->keywordMatched);
    }

    public function testContainsModePassesWhenKeywordPresent(): void
    {
        $client = FakeHttpClient::returning(new HttpResponse(200, 'welcome home', 1));
        $result = (new HttpKeywordChecker($client, 'uphptime-test'))->check($this->site(KeywordMode::Contains, 'home'), 5.0);

        self::assertSame(CheckOutcome::Pass, $result->outcome);
        self::assertTrue($result->keywordMatched);
    }

    public function testNotContainsModePassesWhenKeywordAbsent(): void
    {
        $client = FakeHttpClient::returning(new HttpResponse(200, 'all clear', 1));
        $result = (new HttpKeywordChecker($client, 'uphptime-test'))->check($this->site(KeywordMode::NotContains, 'error'), 5.0);

        self::assertSame(CheckOutcome::Pass, $result->outcome);
    }

    public function testStatusFailureShortCircuitsBeforeKeywordCheck(): void
    {
        $client = FakeHttpClient::returning(new HttpResponse(500, 'home', 1));
        $result = (new HttpKeywordChecker($client, 'uphptime-test'))->check($this->site(KeywordMode::Contains, 'home'), 5.0);

        self::assertSame(CheckOutcome::Fail, $result->outcome);
        self::assertSame('non_2xx_status', $result->error);
        self::assertNull($result->keywordMatched);
    }

    private function site(KeywordMode $mode, string $keyword): Site
    {
        return new Site(
            id: 'site-1',
            name: 'Example',
            url: 'https://example.test/',
            host: null,
            port: null,
            checkType: CheckType::HttpKeyword,
            keyword: $keyword,
            keywordMode: $mode,
            checkIntervalSeconds: null,
            enabled: true,
            createdAt: new DateTimeImmutable('2026-01-01T00:00:00+00:00'),
            state: new SiteRuntimeState(SiteState::Unknown, 0, null, null, new DateTimeImmutable('2026-01-01T00:00:00+00:00'), null),
        );
    }
}

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

namespace Uphptime\Tests\Pinger;

use DateTimeImmutable;
use PHPUnit\Framework\TestCase;
use Uphptime\Pinger\CacheFingerprint;
use Uphptime\Site\CheckType;
use Uphptime\Site\KeywordMode;
use Uphptime\Site\Site;
use Uphptime\Site\SiteRuntimeState;
use Uphptime\Site\SiteState;

final class CacheFingerprintTest extends TestCase
{
    private static function site(
        CheckType $checkType,
        ?string $url,
        ?string $host = null,
        ?int $port = null,
        ?string $keyword = null,
        ?KeywordMode $keywordMode = null,
    ): Site {
        $now = new DateTimeImmutable('2026-01-01T00:00:00+00:00');

        return new Site('s', 's', $url, $host, $port, $checkType, $keyword, $keywordMode, null, true, $now, new SiteRuntimeState(SiteState::Unknown, 0, null, null, $now, null));
    }

    public function testAnyDifferingFieldChangesTheHash(): void
    {
        $base = self::site(CheckType::Http, 'http://example.test/');

        self::assertNotSame(CacheFingerprint::forSite($base), CacheFingerprint::forSite(self::site(CheckType::Http, 'http://other.test/')));
        self::assertNotSame(CacheFingerprint::forSite($base), CacheFingerprint::forSite(self::site(CheckType::Tcp, null, 'example.test', 25)));
        self::assertNotSame(
            CacheFingerprint::forSite($base),
            CacheFingerprint::forSite(self::site(CheckType::HttpKeyword, 'http://example.test/', keyword: 'ok', keywordMode: KeywordMode::Contains)),
        );
    }

    public function testIdenticalCheckRequestsHashIdentically(): void
    {
        $a = self::site(CheckType::HttpKeyword, 'http://example.test/', keyword: 'ok', keywordMode: KeywordMode::Contains);
        $b = self::site(CheckType::HttpKeyword, 'http://example.test/', keyword: 'ok', keywordMode: KeywordMode::Contains);

        self::assertSame(CacheFingerprint::forSite($a), CacheFingerprint::forSite($b));
    }
}

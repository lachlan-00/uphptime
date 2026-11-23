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

namespace Uphptime\Tests\Reporting;

use DateTimeImmutable;
use PHPUnit\Framework\TestCase;
use Uphptime\Reporting\ExpectedChecksCalculator;
use Uphptime\Site\CheckType;
use Uphptime\Site\Site;
use Uphptime\Site\SiteRuntimeState;
use Uphptime\Site\SiteState;

final class ExpectedChecksCalculatorTest extends TestCase
{
    public function testPastDayUsesTheFullDayAtTheSiteOwnInterval(): void
    {
        $site = $this->site(checkIntervalSeconds: 600);

        self::assertSame(144, ExpectedChecksCalculator::forDay($site, '2026-11-01', new DateTimeImmutable('2026-11-05T12:00:00+00:00'), 300));
    }

    public function testPerSiteIntervalOverridesTheNodeDefault(): void
    {
        $site = $this->site(checkIntervalSeconds: 60);

        self::assertSame(60, ExpectedChecksCalculator::forDay($site, '2026-11-01', new DateTimeImmutable('2026-11-01T01:00:00+00:00'), 300));
    }

    public function testTodayUsesOnlyTheElapsedFraction(): void
    {
        $site = $this->site(checkIntervalSeconds: null);

        // Default 300-second interval, 1 hour elapsed since midnight -> 12 checks expected so far.
        self::assertSame(12, ExpectedChecksCalculator::forDay($site, '2026-11-01', new DateTimeImmutable('2026-11-01T01:00:00+00:00'), 300));
    }

    private function site(?int $checkIntervalSeconds): Site
    {
        $now = new DateTimeImmutable('2026-01-01T00:00:00+00:00');

        return new Site(
            id: 'a',
            name: 'a',
            url: 'https://example.test/',
            host: null,
            port: null,
            checkType: CheckType::Http,
            keyword: null,
            keywordMode: null,
            checkIntervalSeconds: $checkIntervalSeconds,
            enabled: true,
            createdAt: $now,
            state: new SiteRuntimeState(SiteState::Up, 0, null, $now, $now, null),
        );
    }
}

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
use Uphptime\Config\Config;
use Uphptime\Reporting\SiteReportSetBuilder;
use Uphptime\Reporting\UptimeReportCalculator;
use Uphptime\Site\CheckType;
use Uphptime\Site\Site;
use Uphptime\Site\SiteRuntimeState;
use Uphptime\Site\SiteState;
use Uphptime\Tests\Storage\InMemoryHistoryRepository;
use Uphptime\Tests\Storage\InMemoryRollupRepository;
use Uphptime\Tests\Storage\InMemorySiteRepository;
use Uphptime\Tests\Support\FakeClock;

final class SiteReportSetBuilderTest extends TestCase
{
    public function testGroupFilterOnlyIncludesMatchingSites(): void
    {
        $sites = new InMemorySiteRepository();
        $sites->save($this->site('a', ['prod']));
        $sites->save($this->site('b', ['staging']));

        $result = $this->builder($sites)->build('overall', null, null, 'prod');

        self::assertNotNull($result);
        self::assertCount(1, $result->items);
        self::assertSame('a', $result->items[0]['id']);
    }

    public function testNoGroupIncludesEverySite(): void
    {
        $sites = new InMemorySiteRepository();
        $sites->save($this->site('a', ['prod']));
        $sites->save($this->site('b', ['staging']));

        $result = $this->builder($sites)->build('overall', null, null, null);

        self::assertNotNull($result);
        self::assertCount(2, $result->items);
    }

    public function testResolvePeriodInfersDayFromDateAloneAndMonthFromMonthAlone(): void
    {
        self::assertSame('day', SiteReportSetBuilder::resolvePeriod(null, '2026-01-01', null));
        self::assertSame('month', SiteReportSetBuilder::resolvePeriod(null, null, '2026-01'));
        self::assertSame('overall', SiteReportSetBuilder::resolvePeriod(null, null, null));
    }

    public function testResolvePeriodPrefersAnExplicitPeriodOverDateOrMonth(): void
    {
        self::assertSame('overall', SiteReportSetBuilder::resolvePeriod('overall', '2026-01-01', '2026-01'));
    }

    public function testUnknownPeriodReturnsNull(): void
    {
        self::assertNull($this->builder(new InMemorySiteRepository())->build('decade', null, null, null));
    }

    private function builder(InMemorySiteRepository $sites): SiteReportSetBuilder
    {
        $clock      = new FakeClock('2026-01-15T00:00:00+00:00');
        $calculator = new UptimeReportCalculator(new InMemoryHistoryRepository(), new InMemoryRollupRepository(), $clock);

        return new SiteReportSetBuilder($sites, $calculator, $clock, new Config('data', 300, 90, 10, 240, 900, 'uphptime-test', 'UTC'));
    }

    /** @param string[] $groups */
    private function site(string $id, array $groups): Site
    {
        $now = new DateTimeImmutable('2026-01-01T00:00:00+00:00');

        return new Site(
            id: $id,
            name: 'Example ' . $id,
            url: 'https://example.test/',
            host: null,
            port: null,
            checkType: CheckType::Http,
            keyword: null,
            keywordMode: null,
            checkIntervalSeconds: null,
            enabled: true,
            createdAt: $now,
            state: new SiteRuntimeState(SiteState::Up, 0, null, $now, $now, null),
            groups: $groups,
        );
    }
}

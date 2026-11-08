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
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Slim\Psr7\Factory\ResponseFactory;
use Slim\Psr7\Factory\ServerRequestFactory;
use Uphptime\Api\Actions\ReportOverviewAction;
use Uphptime\Config\Config;
use Uphptime\History\DailyRollup;
use Uphptime\Reporting\SiteReportSetBuilder;
use Uphptime\Reporting\UptimeReportCalculator;
use Uphptime\Site\CheckType;
use Uphptime\Site\Site;
use Uphptime\Site\SiteRuntimeState;
use Uphptime\Site\SiteState;
use Uphptime\Storage\SiteRepositoryInterface;
use Uphptime\Tests\Storage\InMemoryHistoryRepository;
use Uphptime\Tests\Storage\InMemoryRollupRepository;
use Uphptime\Tests\Storage\InMemorySiteRepository;
use Uphptime\Tests\Support\FakeClock;

final class ReportOverviewActionTest extends TestCase
{
    public function testGroupFilterOnlyIncludesMatchingSites(): void
    {
        $sites = new InMemorySiteRepository();
        $sites->save($this->site('a', ['prod']));
        $sites->save($this->site('b', ['staging']));

        $body = $this->decoded($this->action($sites)->handle($this->requestFor(['group' => 'prod'])));

        self::assertCount(1, $body['items']);
        self::assertSame('a', $body['items'][0]['id']);
    }

    public function testOverallMergesEveryItemsFigures(): void
    {
        $sites    = new InMemorySiteRepository();
        $sites->save($this->site('a', []));
        $sites->save($this->site('b', []));
        $rollups  = new InMemoryRollupRepository();
        $rollups->write(new DailyRollup('a', '2026-01-01', 10, 10, 0, 0, 0, 10, 0, 100.0, null, null, new DateTimeImmutable('2026-01-02')));
        $rollups->write(new DailyRollup('b', '2026-01-01', 10, 5, 5, 0, 0, 10, 0, 50.0, null, null, new DateTimeImmutable('2026-01-02')));

        $body = $this->decoded($this->action($sites, $rollups)->handle($this->requestFor(['period' => 'month', 'month' => '2026-01'])));

        self::assertSame(20, $body['overall']['checks_total']);
        self::assertSame(15, $body['overall']['checks_pass']);
    }

    public function testUnrecognizedPeriodIs422(): void
    {
        $response = $this->action(new InMemorySiteRepository())->handle($this->requestFor(['period' => 'decade']));

        self::assertSame(422, $response->getStatusCode());
    }

    public function testWithNoSitesReturnsAnEmptyOverallReport(): void
    {
        $body = $this->decoded($this->action(new InMemorySiteRepository())->handle($this->requestFor([])));

        self::assertSame([], $body['items']);
        self::assertSame(0, $body['overall']['checks_total']);
        self::assertNull($body['overall']['uptime_percent']);
    }

    private function action(SiteRepositoryInterface $sites, ?InMemoryRollupRepository $rollups = null): ReportOverviewAction
    {
        $clock      = new FakeClock('2026-01-15T00:00:00+00:00');
        $calculator = new UptimeReportCalculator(new InMemoryHistoryRepository(), $rollups ?? new InMemoryRollupRepository(), $clock);
        $builder    = new SiteReportSetBuilder($sites, $calculator, $clock, new Config('data', 300, 90, 10, 240, 900, 'uphptime-test', 'UTC'));

        return new ReportOverviewAction($builder, new ResponseFactory());
    }

    /** @return array<string, mixed> */
    private function decoded(ResponseInterface $response): array
    {
        $decoded = json_decode((string) $response->getBody(), true);

        return is_array($decoded) ? $decoded : [];
    }

    /** @param array<int, string> $query */
    private function requestFor(array $query): ServerRequestInterface
    {
        return (new ServerRequestFactory())->createServerRequest('GET', '/report/v1/overview')->withQueryParams($query);
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

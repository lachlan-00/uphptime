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

namespace Uphptime\Reporting;

use DateTimeImmutable;
use Uphptime\Config\Config;
use Uphptime\Site\Site;
use Uphptime\Storage\SiteRepositoryInterface;
use Uphptime\Support\ClockInterface;

/**
 * Every site's (optionally group-filtered) uptime report for one period, plus the merged overall figure.
 * Shared by the HTTP report API and the CLI's `report --group=` so the two never report different numbers.
 */
final class SiteReportSetBuilder
{
    public function __construct(
        private readonly SiteRepositoryInterface $siteRepository,
        private readonly UptimeReportCalculator $calculator,
        private readonly ClockInterface $clock,
        private readonly Config $config,
    ) {}

    // An explicit --period= always wins; otherwise --date=/--month=/--start=+--end= alone implies which period was meant.
    public static function resolvePeriod(?string $period, ?string $date, ?string $month, ?string $start = null, ?string $end = null): string
    {
        if ($period !== null) {
            return $period;
        }

        return match (true) {
            $date !== null => 'day',
            $month !== null => 'month',
            $start !== null || $end !== null => 'range',
            default => 'overall',
        };
    }

    public function build(?string $period, ?string $date, ?string $month, ?string $group, ?string $start = null, ?string $end = null): ?SiteReportSet
    {
        $period = self::resolvePeriod($period, $date, $month, $start, $end);
        if (!in_array($period, ['day', 'month', 'range', 'overall'], true)) {
            return null;
        }
        if ($period === 'range' && ($start === null || $end === null || $start > $end)) {
            return null;
        }

        $now   = $this->clock->now();
        $sites = $this->siteRepository->all();
        if ($group !== null) {
            $sites = array_values(array_filter($sites, static fn(Site $site): bool => in_array($group, $site->groups, true)));
        }

        $overall = UptimeReport::empty();
        $items   = [];
        foreach ($sites as $site) {
            $report  = $this->reportFor($site->id, $period, $date, $month, $now, $start, $end);
            $overall = $overall->merge($report);
            $item    = [
                'id' => $site->id,
                'name' => $site->name,
                'groups' => $site->groups,
                'state' => $site->state->effectiveStatus($now, $this->config->staleAfterSeconds)->value,
                'enabled' => $site->enabled,
            ] + $report->toArray();

            if ($period === 'day') {
                $item['checks_expected'] = ExpectedChecksCalculator::forDay($site, $date ?? $now->format('Y-m-d'), $now, $this->config->checkIntervalSeconds);
            }

            $items[] = $item;
        }

        return new SiteReportSet($period, $now, $items, $overall);
    }

    private function reportFor(string $siteId, string $period, ?string $date, ?string $month, DateTimeImmutable $now, ?string $start, ?string $end): UptimeReport
    {
        return match ($period) {
            'day' => $this->calculator->forDay($siteId, $date ?? $now->format('Y-m-d')),
            'month' => $this->calculator->forMonth($siteId, $month ?? $now->format('Y-m')),
            'range' => $this->calculator->forRange($siteId, (string) $start, (string) $end),
            default => $this->calculator->overall($siteId),
        };
    }
}

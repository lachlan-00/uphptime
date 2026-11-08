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

namespace Uphptime\Cli\Commands;

use Uphptime\Cli\ArgvOptions;
use Uphptime\Cli\CommandInterface;
use Uphptime\Cli\ConsoleOutputInterface;
use Uphptime\Reporting\SiteReportSetBuilder;
use Uphptime\Reporting\UptimeReportCalculator;
use Uphptime\Site\Site;
use Uphptime\Storage\SiteRepositoryInterface;
use Uphptime\Support\ClockInterface;

/**
 * Prints a day/month/overall uptime report as JSON - one site (by --id=, --name=, or a bare positional
 * argument) or a whole --group= at once, the latter sharing SiteReportSetBuilder with the HTTP report API
 * so the two never disagree about the same request.
 */
final class ReportCommand implements CommandInterface
{
    public function __construct(
        private readonly SiteRepositoryInterface $siteRepository,
        private readonly UptimeReportCalculator $calculator,
        private readonly ClockInterface $clock,
        private readonly SiteReportSetBuilder $reportSetBuilder,
        private readonly ConsoleOutputInterface $output,
    ) {}

    public function run(array $args): int
    {
        $options = ArgvOptions::parse($args);

        if (isset($options['group'])) {
            return $this->runForGroup($options['group'], $options);
        }

        return $this->runForOneSite($options, $args);
    }

    /** @param string[] $args */
    private function firstPositionalArg(array $args): ?string
    {
        foreach ($args as $arg) {
            if (!str_starts_with($arg, '--')) {
                return $arg;
            }
        }

        return null;
    }

    /** @return Site[] */
    private function matchesByName(string $name): array
    {
        return array_values(array_filter(
            $this->siteRepository->all(),
            static fn(Site $site): bool => strcasecmp($site->name, $name) === 0,
        ));
    }

    /** @param array<string, string> $options */
    private function runForGroup(string $group, array $options): int
    {
        $period = $options['period'] ?? null;
        $result = $this->reportSetBuilder->build($period, $options['date'] ?? null, $options['month'] ?? null, $group);
        if ($result === null) {
            $this->output->writeErrorLine(json_encode(
                ['error' => sprintf('Unknown period "%s".', SiteReportSetBuilder::resolvePeriod($period, $options['date'] ?? null, $options['month'] ?? null))],
                JSON_THROW_ON_ERROR,
            ));

            return 1;
        }

        $this->output->writeLine(json_encode(
            ['group' => $group, 'period' => $result->period, 'items' => $result->items, 'overall' => $result->overall->toArray()],
            JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT,
        ));

        return 0;
    }

    /**
     * @param array<string, string> $options
     * @param string[] $args
     */
    private function runForOneSite(array $options, array $args): int
    {
        $identifier = $options['id'] ?? $options['name'] ?? $this->firstPositionalArg($args);
        $period     = SiteReportSetBuilder::resolvePeriod($options['period'] ?? null, $options['date'] ?? null, $options['month'] ?? null);

        $site = $identifier !== null ? $this->siteRepository->find($identifier) : null;
        if ($site === null && $identifier !== null) {
            $matches = $this->matchesByName($identifier);
            if (count($matches) === 1) {
                $site = $matches[0];
            } elseif (count($matches) > 1) {
                $ids = implode('", "', array_map(static fn(Site $match): string => $match->id, $matches));
                $this->output->writeErrorLine(json_encode(
                    ['error' => sprintf('Multiple sites named "%s" - use --id= to disambiguate: "%s".', $identifier, $ids)],
                    JSON_THROW_ON_ERROR,
                ));

                return 1;
            }
        }

        if ($site === null) {
            $this->output->writeErrorLine(json_encode(['error' => sprintf('No site with id or name "%s".', $identifier ?? '')], JSON_THROW_ON_ERROR));

            return 1;
        }

        $report = match ($period) {
            'day' => $this->calculator->forDay($site->id, $options['date'] ?? $this->clock->now()->format('Y-m-d')),
            'month' => $this->calculator->forMonth($site->id, $options['month'] ?? $this->clock->now()->format('Y-m')),
            'overall' => $this->calculator->overall($site->id),
            default => null,
        };

        if ($report === null) {
            $this->output->writeErrorLine(json_encode(['error' => sprintf('Unknown period "%s".', $period)], JSON_THROW_ON_ERROR));

            return 1;
        }

        $this->output->writeLine(json_encode(
            ['site_id' => $site->id, 'site_name' => $site->name, 'period' => $period] + $report->toArray(),
            JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT,
        ));

        return 0;
    }
}

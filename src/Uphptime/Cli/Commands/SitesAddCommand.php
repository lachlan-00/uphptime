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

use InvalidArgumentException;
use Uphptime\Cli\ArgvOptions;
use Uphptime\Cli\CommandInterface;
use Uphptime\Cli\ConsoleOutputInterface;
use Uphptime\Site\ChallengeDelivery;
use Uphptime\Site\CheckType;
use Uphptime\Site\KeywordMode;
use Uphptime\Site\Site;
use Uphptime\Site\SiteRuntimeState;
use Uphptime\Site\SiteState;
use Uphptime\Storage\SiteRepositoryInterface;
use Uphptime\Support\ClockInterface;

/**
 * Adds a site with status `unknown` and an immediately-due first check.
 */
final class SitesAddCommand implements CommandInterface
{
    public function __construct(
        private readonly SiteRepositoryInterface $siteRepository,
        private readonly ClockInterface $clock,
        private readonly ConsoleOutputInterface $output,
    ) {}

    public function run(array $args): int
    {
        $options = ArgvOptions::parse($args);

        try {
            $type = CheckType::from($options['type'] ?? 'http');
            $this->validate($type, $options);
        } catch (InvalidArgumentException|\ValueError $exception) {
            $this->output->writeErrorLine(json_encode(['error' => $exception->getMessage()], JSON_THROW_ON_ERROR));

            return 1;
        }

        $now  = $this->clock->now();
        $site = new Site(
            id: bin2hex(random_bytes(4)),
            name: $options['name'] ?? ($options['url'] ?? $options['host'] ?? 'Unnamed site'),
            url: isset($options['url']) ? trim($options['url']) : null,
            host: isset($options['host']) ? trim($options['host']) : null,
            port: isset($options['port']) ? (int) $options['port'] : null,
            checkType: $type,
            keyword: $options['keyword'] ?? null,
            keywordMode: isset($options['keyword-mode']) ? KeywordMode::from($options['keyword-mode']) : null,
            checkIntervalSeconds: isset($options['interval']) ? (int) $options['interval'] : null,
            enabled: true,
            createdAt: $now,
            state: new SiteRuntimeState(
                status: SiteState::Unknown,
                consecutiveFails: 0,
                lastOutcome: null,
                lastCheckedAt: null,
                nextDueAt: $now,
                lastTransitionAt: null,
            ),
            challengeDelivery: isset($options['challenge-delivery']) ? ChallengeDelivery::from($options['challenge-delivery']) : null,
            groups: $this->parseGroups($options['groups'] ?? null),
        );

        $this->siteRepository->save($site);

        $this->output->writeLine(json_encode([
            'id' => $site->id,
            'name' => $site->name,
            'check_type' => $site->checkType->value,
            'state' => $site->state->status->value,
        ], JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT));

        return 0;
    }

    // Comma-separated --groups=prod,web, trimmed and with blanks dropped; omitted = no groups.
    /** @return string[] */
    private function parseGroups(?string $raw): array
    {
        if ($raw === null || $raw === '') {
            return [];
        }

        return array_values(array_filter(array_map('trim', explode(',', $raw)), static fn(string $group): bool => $group !== ''));
    }

    /**
     * @param array<string, string> $options
     * @param string[] $required
     */
    private function require(array $options, array $required): void
    {
        foreach ($required as $key) {
            if (!isset($options[$key])) {
                throw new InvalidArgumentException(sprintf('Missing required --%s option.', $key));
            }
        }
    }

    /** @param array<string, string> $options */
    private function validate(CheckType $type, array $options): void
    {
        match ($type) {
            CheckType::Http => $this->require($options, ['url']),
            CheckType::HttpKeyword => $this->require($options, ['url', 'keyword', 'keyword-mode']),
            CheckType::Tcp => $this->require($options, ['host', 'port']),
            CheckType::Icmp => $this->require($options, ['host']),
        };
    }
}

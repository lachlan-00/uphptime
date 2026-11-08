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

namespace Uphptime\Storage;

use DateTimeImmutable;
use DateTimeInterface;
use Uphptime\Check\CheckOutcome;
use Uphptime\Site\ChallengeDelivery;
use Uphptime\Site\CheckType;
use Uphptime\Site\KeywordMode;
use Uphptime\Site\MaintenanceWindow;
use Uphptime\Site\Site;
use Uphptime\Site\SiteRuntimeState;
use Uphptime\Site\SiteState;
use Uphptime\Support\AtomicWriter;

/**
 * sites.json as a single atomically-rewritten file; every save rewrites the whole file.
 */
final class JsonSiteRepository implements SiteRepositoryInterface
{
    public function __construct(
        private readonly AtomicWriter $writer,
        private readonly string $path,
    ) {}

    public function all(): array
    {
        return array_map(
            fn(array $data): Site => $this->siteFromArray($data),
            $this->sitesFrom($this->readFile()),
        );
    }

    public function find(string $id): ?Site
    {
        foreach ($this->all() as $site) {
            if ($site->id === $id) {
                return $site;
            }
        }

        return null;
    }

    // readModifyWrite() holds one lock across the whole read-decide-write sequence, so two concurrent
    // removes/saves can never race on a stale read (the lost-update bug a separate read()+write() has).
    public function remove(string $id): void
    {
        $this->writer->readModifyWrite($this->path, function (?string $current) use ($id): string {
            $sites = array_values(array_filter(
                $this->sitesFrom($this->decodeFile($current)),
                static fn(array $existing): bool => $existing['id'] !== $id,
            ));

            return $this->encodeFile(['schema_version' => 1, 'sites' => $sites]);
        });
    }

    // Replaces the site with the same id, or appends it if new, then rewrites the whole file atomically.
    public function save(Site $site): void
    {
        $this->writer->readModifyWrite($this->path, function (?string $current) use ($site): string {
            $sites = $this->sitesFrom($this->decodeFile($current));
            $found = false;

            foreach ($sites as $index => $existing) {
                if ($existing['id'] === $site->id) {
                    $sites[$index] = $this->siteToArray($site);
                    $found         = true;
                    break;
                }
            }

            if (!$found) {
                $sites[] = $this->siteToArray($site);
            }

            return $this->encodeFile(['schema_version' => 1, 'sites' => $sites]);
        });
    }

    /** @return array<string, mixed> */
    private function decodeFile(?string $contents): array
    {
        if ($contents === null) {
            return ['schema_version' => 1, 'sites' => []];
        }

        $decoded = json_decode($contents, true);

        return is_array($decoded) ? $decoded : ['schema_version' => 1, 'sites' => []];
    }

    /** @param array{schema_version: int, sites: array<int, array<string, mixed>>} $data */
    private function encodeFile(array $data): string
    {
        return json_encode($data, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR) . "\n";
    }

    /**
     * @param array<string, mixed> $data
     * @return string[]
     */
    private function groupsFromArray(array $data): array
    {
        $groups = $data['groups'] ?? [];

        return is_array($groups) ? array_values(array_map(static fn(mixed $group): string => (string) $group, $groups)) : [];
    }

    /**
     * @param array<string, mixed> $data
     * @return MaintenanceWindow[]
     */
    private function maintenanceWindowsFromArray(array $data): array
    {
        $rawWindows = $data['maintenance_windows'] ?? [];
        if (!is_array($rawWindows)) {
            return [];
        }

        $windows = [];
        foreach ($rawWindows as $rawWindow) {
            if (!is_array($rawWindow)) {
                continue;
            }

            $windows[] = new MaintenanceWindow(
                id: (string) $rawWindow['id'],
                startsAt: new DateTimeImmutable((string) $rawWindow['starts_at']),
                endsAt: new DateTimeImmutable((string) $rawWindow['ends_at']),
                note: $rawWindow['note'] ?? null,
            );
        }

        return $windows;
    }

    /** @return array<string, mixed> */
    private function readFile(): array
    {
        return $this->decodeFile($this->writer->read($this->path));
    }

    /** @param array<string, mixed> $data */
    private function siteFromArray(array $data): Site
    {
        $state = $data['state'];

        return new Site(
            id: (string) $data['id'],
            name: (string) $data['name'],
            url: $data['url'],
            host: $data['host'],
            port: $data['port'],
            checkType: CheckType::from((string) $data['check_type']),
            keyword: $data['keyword'],
            keywordMode: $data['keyword_mode'] !== null ? KeywordMode::from((string) $data['keyword_mode']) : null,
            checkIntervalSeconds: $data['check_interval_seconds'],
            enabled: (bool) $data['enabled'],
            createdAt: new DateTimeImmutable((string) $data['created_at']),
            state: new SiteRuntimeState(
                status: SiteState::from((string) $state['status']),
                consecutiveFails: (int) $state['consecutive_fails'],
                lastOutcome: $state['last_outcome'] !== null ? CheckOutcome::from((string) $state['last_outcome']) : null,
                lastCheckedAt: $state['last_checked_at'] !== null ? new DateTimeImmutable((string) $state['last_checked_at']) : null,
                nextDueAt: new DateTimeImmutable((string) $state['next_due_at']),
                lastTransitionAt: $state['last_transition_at'] !== null
                    ? new DateTimeImmutable((string) $state['last_transition_at'])
                    : null,
            ),
            maintenanceWindows: $this->maintenanceWindowsFromArray($data),
            challengeDelivery: isset($data['challenge_delivery'])
                ? ChallengeDelivery::from((string) $data['challenge_delivery'])
                : null,
            groups: $this->groupsFromArray($data),
        );
    }

    /**
     * @param array<string, mixed> $data
     * @return array<int, array<string, mixed>>
     */
    private function sitesFrom(array $data): array
    {
        $rawSites = $data['sites'] ?? [];
        if (!is_array($rawSites)) {
            return [];
        }

        $sites = [];
        foreach ($rawSites as $rawSite) {
            if (is_array($rawSite)) {
                $sites[] = $rawSite;
            }
        }

        return $sites;
    }

    /** @return array<string, mixed> */
    private function siteToArray(Site $site): array
    {
        return [
            'id' => $site->id,
            'name' => $site->name,
            'url' => $site->url,
            'host' => $site->host,
            'port' => $site->port,
            'check_type' => $site->checkType->value,
            'keyword' => $site->keyword,
            'keyword_mode' => $site->keywordMode?->value,
            'check_interval_seconds' => $site->checkIntervalSeconds,
            'enabled' => $site->enabled,
            'created_at' => $site->createdAt->format(DateTimeInterface::ATOM),
            'state' => [
                'status' => $site->state->status->value,
                'consecutive_fails' => $site->state->consecutiveFails,
                'last_outcome' => $site->state->lastOutcome?->value,
                'last_checked_at' => $site->state->lastCheckedAt?->format(DateTimeInterface::ATOM),
                'next_due_at' => $site->state->nextDueAt->format(DateTimeInterface::ATOM),
                'last_transition_at' => $site->state->lastTransitionAt?->format(DateTimeInterface::ATOM),
            ],
            'maintenance_windows' => array_map(
                static fn(MaintenanceWindow $window): array => [
                    'id' => $window->id,
                    'starts_at' => $window->startsAt->format(DateTimeInterface::ATOM),
                    'ends_at' => $window->endsAt->format(DateTimeInterface::ATOM),
                    'note' => $window->note,
                ],
                $site->maintenanceWindows,
            ),
            'challenge_delivery' => $site->challengeDelivery?->value,
            'groups' => $site->groups,
        ];
    }
}

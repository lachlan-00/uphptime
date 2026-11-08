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

namespace Uphptime\Storage\Pdo;

use PDO;
use PDOException;
use Uphptime\Check\CheckOutcome;
use Uphptime\Site\ChallengeDelivery;
use Uphptime\Site\CheckType;
use Uphptime\Site\KeywordMode;
use Uphptime\Site\MaintenanceWindow;
use Uphptime\Site\Site;
use Uphptime\Site\SiteRuntimeState;
use Uphptime\Site\SiteState;
use Uphptime\Storage\SiteRepositoryInterface;

/**
 * Sites plus their embedded runtime state flattened onto one row - every state field is a small
 * scalar, and next_due_at specifically wants to be a real indexed column. Maintenance windows live in
 * their own child table (ON DELETE CASCADE), same pattern as a peer's target allowlist.
 */
final class PdoSiteRepository implements SiteRepositoryInterface
{
    use PdoAccess;

    private const COLUMNS = [
        'id', 'name', 'url', 'host', 'port', 'check_type', 'keyword', 'keyword_mode',
        'check_interval_seconds', 'enabled', 'created_at', 'status', 'consecutive_fails',
        'last_outcome', 'last_checked_at', 'next_due_at', 'last_transition_at', 'challenge_delivery',
    ];

    public function __construct(
        private readonly PDO $pdo,
        private readonly SqlDialect $dialect,
    ) {}

    public function all(): array
    {
        return array_map(
            fn(array $row): Site => $this->fromRow($row),
            $this->query('SELECT * FROM sites ORDER BY next_due_at ASC'),
        );
    }

    public function find(string $id): ?Site
    {
        $rows = $this->query('SELECT * FROM sites WHERE id = ?', [$id]);

        return $rows === [] ? null : $this->fromRow($rows[0]);
    }

    public function remove(string $id): void
    {
        $this->execute('DELETE FROM sites WHERE id = ?', [$id]);
    }

    // One transaction: upsert the sites row, then fully replace its maintenance windows and groups, so
    // a concurrent reader never sees a half-replaced child-row set.
    public function save(Site $site): void
    {
        try {
            $this->pdo->beginTransaction();
            $this->upsertSiteRow($site);
            $this->replaceMaintenanceWindows($site);
            $this->replaceGroups($site);
            $this->pdo->commit();
        } catch (PDOException $exception) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }

            PdoExceptionTranslator::translate($exception);
        }
    }

    /** @param array<string, mixed> $row */
    private function fromRow(array $row): Site
    {
        $siteId = (string) $row['id'];

        return new Site(
            id: $siteId,
            name: (string) $row['name'],
            url: $row['url'],
            host: $row['host'],
            port: $row['port'] !== null ? (int) $row['port'] : null,
            checkType: CheckType::from((string) $row['check_type']),
            keyword: $row['keyword'],
            keywordMode: $row['keyword_mode'] !== null ? KeywordMode::from((string) $row['keyword_mode']) : null,
            checkIntervalSeconds: $row['check_interval_seconds'] !== null ? (int) $row['check_interval_seconds'] : null,
            enabled: $this->dialect->decodeBool($row['enabled']),
            createdAt: PdoTimestamp::parse((string) $row['created_at']),
            state: new SiteRuntimeState(
                status: SiteState::from((string) $row['status']),
                consecutiveFails: (int) $row['consecutive_fails'],
                lastOutcome: $row['last_outcome'] !== null ? CheckOutcome::from((string) $row['last_outcome']) : null,
                lastCheckedAt: $row['last_checked_at'] !== null ? PdoTimestamp::parse((string) $row['last_checked_at']) : null,
                nextDueAt: PdoTimestamp::parse((string) $row['next_due_at']),
                lastTransitionAt: $row['last_transition_at'] !== null ? PdoTimestamp::parse((string) $row['last_transition_at']) : null,
            ),
            maintenanceWindows: $this->maintenanceWindowsFor($siteId),
            challengeDelivery: $row['challenge_delivery'] !== null ? ChallengeDelivery::from((string) $row['challenge_delivery']) : null,
            groups: $this->groupsFor($siteId),
        );
    }

    /** @return string[] */
    private function groupsFor(string $siteId): array
    {
        return array_map(
            static fn(array $row): string => (string) $row['group_name'],
            $this->query('SELECT * FROM site_groups WHERE site_id = ? ORDER BY id ASC', [$siteId]),
        );
    }

    /** @return MaintenanceWindow[] */
    private function maintenanceWindowsFor(string $siteId): array
    {
        return array_map(
            fn(array $row): MaintenanceWindow => new MaintenanceWindow(
                id: (string) $row['window_id'],
                startsAt: PdoTimestamp::parse((string) $row['starts_at']),
                endsAt: PdoTimestamp::parse((string) $row['ends_at']),
                note: $row['note'],
            ),
            $this->query('SELECT * FROM site_maintenance_windows WHERE site_id = ? ORDER BY id ASC', [$siteId]),
        );
    }

    private function replaceGroups(Site $site): void
    {
        $this->rawExecute('DELETE FROM site_groups WHERE site_id = ?', [$site->id]);

        foreach ($site->groups as $group) {
            $this->rawExecute('INSERT INTO site_groups (site_id, group_name) VALUES (?, ?)', [$site->id, $group]);
        }
    }

    private function replaceMaintenanceWindows(Site $site): void
    {
        $this->rawExecute('DELETE FROM site_maintenance_windows WHERE site_id = ?', [$site->id]);

        foreach ($site->maintenanceWindows as $window) {
            $this->rawExecute(
                'INSERT INTO site_maintenance_windows (site_id, window_id, starts_at, ends_at, note) VALUES (?, ?, ?, ?, ?)',
                [$site->id, $window->id, PdoTimestamp::format($window->startsAt), PdoTimestamp::format($window->endsAt), $window->note],
            );
        }
    }

    private function upsertSiteRow(Site $site): void
    {
        $update = array_values(array_diff(self::COLUMNS, ['id']));
        $sql    = sprintf(
            'INSERT INTO sites (%s) VALUES (%s)',
            implode(', ', self::COLUMNS),
            implode(', ', array_fill(0, count(self::COLUMNS), '?')),
        ) . $this->dialect->upsertSuffix(self::COLUMNS, ['id'], $update);

        $this->rawExecute($sql, [
            $site->id,
            $site->name,
            $site->url,
            $site->host,
            $site->port,
            $site->checkType->value,
            $site->keyword,
            $site->keywordMode?->value,
            $site->checkIntervalSeconds,
            $site->enabled ? 1 : 0,
            PdoTimestamp::format($site->createdAt),
            $site->state->status->value,
            $site->state->consecutiveFails,
            $site->state->lastOutcome?->value,
            $site->state->lastCheckedAt !== null ? PdoTimestamp::format($site->state->lastCheckedAt) : null,
            PdoTimestamp::format($site->state->nextDueAt),
            $site->state->lastTransitionAt !== null ? PdoTimestamp::format($site->state->lastTransitionAt) : null,
            $site->challengeDelivery?->value,
        ]);
    }
}

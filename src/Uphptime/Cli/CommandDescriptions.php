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

namespace Uphptime\Cli;

/**
 * One-line, human-readable blurbs for `help`, kept separate from the action map itself so
 * CliApplication's registered actions (the authoritative list) never have to carry display text.
 */
final class CommandDescriptions
{
    /** @return array<string, string> */
    public static function all(): array
    {
        return [
            'sites:add' => 'Add a new site to monitor',
            'sites:list' => 'List sites and their current status',
            'sites:remove' => "Remove a site (pass --purge-history to also delete its history/rollups)",
            'maintenance:add' => 'Add a one-off maintenance window for a site',
            'maintenance:list' => "List a site's maintenance windows",
            'maintenance:remove' => "Remove one of a site's maintenance windows",
            'check:run' => 'Run one scheduled check pass (normally invoked by cron)',
            'report' => 'Print a day/month/overall uptime report for one site (--id=/--name=/positional) or a whole --group=',
            'pinger:refresh-cache' => "Proactively refresh this node's delegated-check result cache",
            'peers:add' => 'Add a peer and its first key (admin-to-admin key exchange)',
            'peers:list' => 'List peers, their keys (never secrets) and current reputation',
            'peers:remove' => 'Remove a peer (pass --purge-reputation to also drop its scoring history)',
            'peers:set' => "Update an existing peer's settings without touching its keys",
            'peers:keys:add' => 'Add an additional key to an existing peer',
            'peers:keys:rotate' => "Rotate a peer's active outbound key, with no hard-cutover moment",
            'peers:keys:retire' => "Permanently remove one of a peer's keys (manual revocation)",
            'peers:allowlist:add' => 'Scope which targets a peer may be asked to probe',
            'peers:allowlist:remove' => "Remove one pattern from a peer's target allowlist",
            'peers:allowlist:clear' => "Clear a peer's entire target allowlist",
            'peers:reputation:reset' => "Reset a peer's local reputation back to neutral",
            'storage:install' => 'Create/upgrade the MySQL or PostgreSQL schema (idempotent)',
            'storage:export' => "Copy this node's full data to a JSON-layout directory",
            'storage:import' => "Restore this node's full data from a JSON-layout directory",
        ];
    }
}

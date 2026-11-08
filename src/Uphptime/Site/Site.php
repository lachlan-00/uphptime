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

namespace Uphptime\Site;

use DateTimeImmutable;

/**
 * One monitored site: its static configuration plus its current runtime state.
 */
final class Site
{
    /**
     * @param MaintenanceWindow[] $maintenanceWindows
     * @param string[] $groups
     */
    public function __construct(
        public readonly string $id,
        public readonly string $name,
        public readonly ?string $url,
        public readonly ?string $host,
        public readonly ?int $port,
        public readonly CheckType $checkType,
        public readonly ?string $keyword,
        public readonly ?KeywordMode $keywordMode,
        public readonly ?int $checkIntervalSeconds,
        public readonly bool $enabled,
        public readonly DateTimeImmutable $createdAt,
        public readonly SiteRuntimeState $state,
        public readonly array $maintenanceWindows = [],
        public readonly ?ChallengeDelivery $challengeDelivery = null,
        public readonly array $groups = [],
    ) {}

    // Resolves the effective check interval: the per-site override, or the node default.
    public function intervalSeconds(int $defaultIntervalSeconds): int
    {
        return $this->checkIntervalSeconds ?? $defaultIntervalSeconds;
    }

    // Whether $when falls inside any of this site's currently configured maintenance windows.
    public function isUnderMaintenanceAt(DateTimeImmutable $when): bool
    {
        foreach ($this->maintenanceWindows as $window) {
            if ($window->contains($when)) {
                return true;
            }
        }

        return false;
    }

    // Attestation Layer 1 only applies to HTTP(S) checks - TCP/ICMP have no response to carry a token in.
    public function usesChallengeToken(): bool
    {
        return $this->challengeDelivery !== null && in_array($this->checkType, [CheckType::Http, CheckType::HttpKeyword], true);
    }

    // Returns a copy of this site with its challenge delivery mode replaced.
    public function withChallengeDelivery(?ChallengeDelivery $challengeDelivery): self
    {
        return new self(
            $this->id,
            $this->name,
            $this->url,
            $this->host,
            $this->port,
            $this->checkType,
            $this->keyword,
            $this->keywordMode,
            $this->checkIntervalSeconds,
            $this->enabled,
            $this->createdAt,
            $this->state,
            $this->maintenanceWindows,
            $challengeDelivery,
            $this->groups,
        );
    }

    // Returns a copy of this site with its groups replaced.
    /** @param string[] $groups */
    public function withGroups(array $groups): self
    {
        return new self(
            $this->id,
            $this->name,
            $this->url,
            $this->host,
            $this->port,
            $this->checkType,
            $this->keyword,
            $this->keywordMode,
            $this->checkIntervalSeconds,
            $this->enabled,
            $this->createdAt,
            $this->state,
            $this->maintenanceWindows,
            $this->challengeDelivery,
            $groups,
        );
    }

    // Returns a copy of this site with its maintenance windows replaced.
    /** @param MaintenanceWindow[] $maintenanceWindows */
    public function withMaintenanceWindows(array $maintenanceWindows): self
    {
        return new self(
            $this->id,
            $this->name,
            $this->url,
            $this->host,
            $this->port,
            $this->checkType,
            $this->keyword,
            $this->keywordMode,
            $this->checkIntervalSeconds,
            $this->enabled,
            $this->createdAt,
            $this->state,
            $maintenanceWindows,
            $this->challengeDelivery,
            $this->groups,
        );
    }

    // Returns a copy of this site with its runtime state replaced.
    public function withState(SiteRuntimeState $state): self
    {
        return new self(
            $this->id,
            $this->name,
            $this->url,
            $this->host,
            $this->port,
            $this->checkType,
            $this->keyword,
            $this->keywordMode,
            $this->checkIntervalSeconds,
            $this->enabled,
            $this->createdAt,
            $state,
            $this->maintenanceWindows,
            $this->challengeDelivery,
            $this->groups,
        );
    }
}

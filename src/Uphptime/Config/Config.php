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

namespace Uphptime\Config;

/**
 * Immutable, resolved node configuration, static + injectable per the project's config convention.
 */
final class Config
{
    private static ?self $instance = null;

    /**
     * @param array<int, int> $tcpPortAllowlist
     * @param array<int, string> $egressAllowedRanges
     */
    public function __construct(
        public readonly string $dataDirectory,
        public readonly int $checkIntervalSeconds,
        public readonly int $retentionDays,
        public readonly int $requestTimeoutSeconds,
        public readonly int $runBudgetSeconds,
        public readonly int $staleAfterSeconds,
        public readonly string $httpUserAgent,
        public readonly string $timezone,
        public readonly bool $pingerEnabled = true,
        public readonly int $clockSkewSeconds = 60,
        public readonly int $defaultPeerRateLimitPerMinute = 60,
        public readonly int $peerRequestTimeoutSeconds = 10,
        public readonly int $pingerMaxTimeoutSeconds = 15,
        public readonly int $maxRedirects = 0,
        public readonly int $responseSizeCapBytes = 2_097_152,
        public readonly array $tcpPortAllowlist = [21, 22, 25, 53, 80, 110, 143, 443, 465, 587, 993, 995, 3306, 5432, 6379, 8080, 8443],
        public readonly array $egressAllowedRanges = [],
        public readonly bool $pingerCacheEnabled = true,
        public readonly int $pingerCacheTtlSeconds = 60,
        public readonly int $pingerCacheRefreshLeadSeconds = 10,
        public readonly int $pingerCacheIdleEvictionSeconds = 3600,
        public readonly int $pingerCacheMaxTrackedTargets = 500,
        public readonly int $maxDelegatesPerAcquisition = 3,
        public readonly bool $localFailureCountsAsDown = true,
        public readonly float $canarySampleRate = 0.07,
        public readonly float $peerReputationNeutralScore = 100.0,
        public readonly float $peerReputationEwmaAlpha = 0.2,
        public readonly float $peerReputationDisagreementAlpha = 0.35,
        public readonly float $peerReputationConfirmationDisagreementAlpha = 0.1,
        public readonly float $peerReputationFalsePassAlpha = 0.5,
        public readonly float $peerReputationExclusionThreshold = 40.0,
        public readonly int $peerReputationCooldownSeconds = 3600,
        public readonly float $peerReputationCooldownBackoffMultiplier = 2.0,
        public readonly int $peerReputationMaxCooldownSeconds = 86_400,
        public readonly float $peerReputationProbationScore = 45.0,
        public readonly string $storageBackend = 'json',
        public readonly string $sqlHost = '127.0.0.1',
        public readonly int $sqlPort = 0,
        public readonly string $sqlDatabase = 'uphptime',
        public readonly string $sqlUsername = '',
        public readonly string $sqlPassword = '',
        public readonly int $challengeTokenTtlSeconds = 300,
        public readonly string $reportApiKey = '',
        public readonly string $reportCorsAllowedOrigin = '*',
    ) {}

    // Returns the process-wide config instance, loading it from $configPath on first use.
    public static function current(?string $configPath = null): self
    {
        if (self::$instance === null) {
            self::$instance = (new ConfigLoader())->load($configPath ?? dirname(__DIR__, 2) . '/config/node.json');
        }

        return self::$instance;
    }

    // Clears the process-wide instance so the next current() call reloads from disk.
    public static function reset(): void
    {
        self::$instance = null;
    }

    // Overrides the process-wide instance; used by the CLI bootstrap and by tests.
    public static function setCurrent(self $config): void
    {
        self::$instance = $config;
    }
}

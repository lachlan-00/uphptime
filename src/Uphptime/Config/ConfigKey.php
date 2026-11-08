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
 * The node-level settings keys stored in config/node.json, each with its own default.
 */
enum ConfigKey: string
{
    case CanarySampleRate                            = 'canary_sample_rate';
    case ChallengeTokenTtlSeconds                    = 'challenge_token_ttl_seconds';
    case CheckIntervalSeconds                        = 'check_interval_seconds';
    case ClockSkewSeconds                            = 'clock_skew_seconds';
    case DataDirectory                               = 'data_dir';
    case DefaultPeerRateLimitPerMinute               = 'default_peer_rate_limit_per_minute';
    case EgressAllowedRanges                         = 'egress_allowed_ranges';
    case HttpUserAgent                               = 'http_user_agent';
    case MaxDelegatesPerAcquisition                  = 'max_delegates_per_acquisition';
    case MaxRedirects                                = 'max_redirects';
    case PeerReputationConfirmationDisagreementAlpha = 'peer_reputation_confirmation_disagreement_alpha';
    case PeerReputationCooldownBackoffMultiplier     = 'peer_reputation_cooldown_backoff_multiplier';
    case PeerReputationCooldownSeconds               = 'peer_reputation_cooldown_seconds';
    case PeerReputationDisagreementAlpha             = 'peer_reputation_disagreement_alpha';
    case PeerReputationEwmaAlpha                     = 'peer_reputation_ewma_alpha';
    case PeerReputationExclusionThreshold            = 'peer_reputation_exclusion_threshold';
    case PeerReputationFalsePassAlpha                = 'peer_reputation_false_pass_alpha';
    case PeerReputationMaxCooldownSeconds            = 'peer_reputation_max_cooldown_seconds';
    case PeerReputationNeutralScore                  = 'peer_reputation_neutral_score';
    case PeerReputationProbationScore                = 'peer_reputation_probation_score';
    case PeerRequestTimeoutSeconds                   = 'peer_request_timeout_seconds';
    case PingerCacheEnabled                          = 'pinger_cache_enabled';
    case PingerCacheIdleEvictionSeconds              = 'pinger_cache_idle_eviction_seconds';
    case PingerCacheMaxTrackedTargets                = 'pinger_cache_max_tracked_targets';
    case PingerCacheRefreshLeadSeconds               = 'pinger_cache_refresh_lead_seconds';
    case PingerCacheTtlSeconds                       = 'pinger_cache_ttl_seconds';
    case PingerEnabled                               = 'pinger_enabled';
    case PingerMaxTimeoutSeconds                     = 'pinger_max_timeout_seconds';
    case ReportApiKey                                = 'report_api_key';
    case ReportCorsAllowedOrigin                     = 'report_cors_allowed_origin';
    case RequestTimeoutSeconds                       = 'request_timeout_seconds';
    case ResponseSizeCapBytes                        = 'response_size_cap_bytes';
    case RetentionDays                               = 'retention_days';
    case RunBudgetSeconds                            = 'run_budget_seconds';
    case SqlDatabase                                 = 'sql_database';
    case SqlHost                                     = 'sql_host';
    case SqlPassword                                 = 'sql_password';
    case SqlPort                                     = 'sql_port';
    case SqlUsername                                 = 'sql_username';
    case StaleAfterSeconds                           = 'stale_after_seconds';
    case StorageBackend                              = 'storage_backend';
    case TcpPortAllowlist                            = 'tcp_port_allowlist';
    case Timezone                                    = 'timezone';

    // The fixed set of values a storage_backend key may hold.
    /** @return string[] */
    public function allowedStrings(): array
    {
        return match ($this) {
            self::StorageBackend => ['json', 'mysql', 'pgsql'],
            default => [],
        };
    }

    // Returns the value used when the key is absent from config/node.json.
    /** @return int|string|bool|float|array<int, int|string> */
    public function default(): int|string|bool|float|array
    {
        return match ($this) {
            self::DataDirectory => 'data',
            self::CheckIntervalSeconds => 300,
            self::RetentionDays => 90,
            self::RequestTimeoutSeconds, self::PeerRequestTimeoutSeconds, self::PingerCacheRefreshLeadSeconds => 10,
            self::RunBudgetSeconds => 240,
            self::StaleAfterSeconds => 900,
            self::HttpUserAgent => '',
            self::Timezone => 'UTC',
            self::PingerEnabled, self::PingerCacheEnabled => true,
            self::ClockSkewSeconds, self::DefaultPeerRateLimitPerMinute, self::PingerCacheTtlSeconds => 60,
            self::PingerMaxTimeoutSeconds => 15,
            self::MaxRedirects => 0,
            self::MaxDelegatesPerAcquisition => 3,
            self::ResponseSizeCapBytes => 2_097_152,
            self::TcpPortAllowlist => [21, 22, 25, 53, 80, 110, 143, 443, 465, 587, 993, 995, 3306, 5432, 6379, 8080, 8443],
            self::EgressAllowedRanges => [],
            self::PingerCacheIdleEvictionSeconds, self::PeerReputationCooldownSeconds => 3600,
            self::PingerCacheMaxTrackedTargets => 500,
            self::CanarySampleRate => 0.07,
            self::ChallengeTokenTtlSeconds => 300,
            self::PeerReputationNeutralScore => 100.0,
            self::PeerReputationEwmaAlpha => 0.2,
            self::PeerReputationDisagreementAlpha => 0.35,
            self::PeerReputationConfirmationDisagreementAlpha => 0.1,
            self::PeerReputationFalsePassAlpha => 0.5,
            self::PeerReputationExclusionThreshold => 40.0,
            self::PeerReputationCooldownBackoffMultiplier => 2.0,
            self::PeerReputationMaxCooldownSeconds => 86_400,
            self::PeerReputationProbationScore => 45.0,
            self::StorageBackend => 'json',
            self::SqlHost => '127.0.0.1',
            self::SqlPort => 0,
            self::SqlDatabase => 'uphptime',
            self::SqlUsername, self::SqlPassword => '',
            self::ReportApiKey => '',
            self::ReportCorsAllowedOrigin => '*',
        };
    }

    // True for keys whose value must be a boolean rather than a string.
    public function isBoolean(): bool
    {
        return match ($this) {
            self::PingerEnabled,
            self::PingerCacheEnabled => true,
            default => false,
        };
    }

    // True for keys whose value must be a float rather than a string.
    public function isFloat(): bool
    {
        return match ($this) {
            self::CanarySampleRate,
            self::PeerReputationNeutralScore,
            self::PeerReputationEwmaAlpha,
            self::PeerReputationDisagreementAlpha,
            self::PeerReputationConfirmationDisagreementAlpha,
            self::PeerReputationFalsePassAlpha,
            self::PeerReputationExclusionThreshold,
            self::PeerReputationCooldownBackoffMultiplier,
            self::PeerReputationProbationScore => true,
            default => false,
        };
    }

    // True for keys whose value must be an integer rather than a string.
    public function isInteger(): bool
    {
        return match ($this) {
            self::ChallengeTokenTtlSeconds,
            self::CheckIntervalSeconds,
            self::ClockSkewSeconds,
            self::DefaultPeerRateLimitPerMinute,
            self::MaxDelegatesPerAcquisition,
            self::MaxRedirects,
            self::PeerReputationCooldownSeconds,
            self::PeerReputationMaxCooldownSeconds,
            self::PeerRequestTimeoutSeconds,
            self::PingerCacheIdleEvictionSeconds,
            self::PingerCacheMaxTrackedTargets,
            self::PingerCacheRefreshLeadSeconds,
            self::PingerCacheTtlSeconds,
            self::PingerMaxTimeoutSeconds,
            self::RequestTimeoutSeconds,
            self::ResponseSizeCapBytes,
            self::RetentionDays,
            self::RunBudgetSeconds,
            self::SqlPort,
            self::StaleAfterSeconds => true,
            default => false,
        };
    }

    // True for keys whose value is a list of integers (e.g. a port allowlist).
    public function isIntegerList(): bool
    {
        return $this === self::TcpPortAllowlist;
    }

    // True for keys whose value is a list of strings (e.g. a CIDR allowlist).
    public function isStringList(): bool
    {
        return $this === self::EgressAllowedRanges;
    }
}

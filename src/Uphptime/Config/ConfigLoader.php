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

use RuntimeException;
use Uphptime\Version;

/**
 * Reads and validates config/node.json, filling in ConfigKey defaults for anything omitted.
 */
final class ConfigLoader
{
    public function load(string $path): Config
    {
        $data = $this->readJsonFile($path);

        return new Config(
            dataDirectory: $this->stringValue($data, ConfigKey::DataDirectory),
            checkIntervalSeconds: $this->intValue($data, ConfigKey::CheckIntervalSeconds),
            retentionDays: $this->intValue($data, ConfigKey::RetentionDays),
            requestTimeoutSeconds: $this->intValue($data, ConfigKey::RequestTimeoutSeconds),
            runBudgetSeconds: $this->intValue($data, ConfigKey::RunBudgetSeconds),
            staleAfterSeconds: $this->intValue($data, ConfigKey::StaleAfterSeconds),
            httpUserAgent: Version::userAgent($this->stringValue($data, ConfigKey::HttpUserAgent)),
            timezone: $this->stringValue($data, ConfigKey::Timezone),
            pingerEnabled: $this->boolValue($data, ConfigKey::PingerEnabled),
            clockSkewSeconds: $this->intValue($data, ConfigKey::ClockSkewSeconds),
            defaultPeerRateLimitPerMinute: $this->intValue($data, ConfigKey::DefaultPeerRateLimitPerMinute),
            peerRequestTimeoutSeconds: $this->intValue($data, ConfigKey::PeerRequestTimeoutSeconds),
            pingerMaxTimeoutSeconds: $this->intValue($data, ConfigKey::PingerMaxTimeoutSeconds),
            maxRedirects: $this->intValue($data, ConfigKey::MaxRedirects),
            responseSizeCapBytes: $this->intValue($data, ConfigKey::ResponseSizeCapBytes),
            tcpPortAllowlist: $this->intListValue($data, ConfigKey::TcpPortAllowlist),
            egressAllowedRanges: $this->stringListValue($data, ConfigKey::EgressAllowedRanges),
            pingerCacheEnabled: $this->boolValue($data, ConfigKey::PingerCacheEnabled),
            pingerCacheTtlSeconds: $this->intValue($data, ConfigKey::PingerCacheTtlSeconds),
            pingerCacheRefreshLeadSeconds: $this->intValue($data, ConfigKey::PingerCacheRefreshLeadSeconds),
            pingerCacheIdleEvictionSeconds: $this->intValue($data, ConfigKey::PingerCacheIdleEvictionSeconds),
            pingerCacheMaxTrackedTargets: $this->intValue($data, ConfigKey::PingerCacheMaxTrackedTargets),
            maxDelegatesPerAcquisition: $this->intValue($data, ConfigKey::MaxDelegatesPerAcquisition),
            localFailureCountsAsDown: $this->boolValue($data, ConfigKey::LocalFailureCountsAsDown),
            canarySampleRate: $this->floatValue($data, ConfigKey::CanarySampleRate),
            peerReputationNeutralScore: $this->floatValue($data, ConfigKey::PeerReputationNeutralScore),
            peerReputationEwmaAlpha: $this->floatValue($data, ConfigKey::PeerReputationEwmaAlpha),
            peerReputationDisagreementAlpha: $this->floatValue($data, ConfigKey::PeerReputationDisagreementAlpha),
            peerReputationConfirmationDisagreementAlpha: $this->floatValue($data, ConfigKey::PeerReputationConfirmationDisagreementAlpha),
            peerReputationFalsePassAlpha: $this->floatValue($data, ConfigKey::PeerReputationFalsePassAlpha),
            peerReputationExclusionThreshold: $this->floatValue($data, ConfigKey::PeerReputationExclusionThreshold),
            peerReputationCooldownSeconds: $this->intValue($data, ConfigKey::PeerReputationCooldownSeconds),
            peerReputationCooldownBackoffMultiplier: $this->floatValue($data, ConfigKey::PeerReputationCooldownBackoffMultiplier),
            peerReputationMaxCooldownSeconds: $this->intValue($data, ConfigKey::PeerReputationMaxCooldownSeconds),
            peerReputationProbationScore: $this->floatValue($data, ConfigKey::PeerReputationProbationScore),
            storageBackend: $this->stringEnumValue($data, ConfigKey::StorageBackend),
            sqlHost: $this->stringValue($data, ConfigKey::SqlHost),
            sqlPort: $this->intValue($data, ConfigKey::SqlPort),
            sqlDatabase: $this->stringValue($data, ConfigKey::SqlDatabase),
            sqlUsername: $this->stringValue($data, ConfigKey::SqlUsername),
            sqlPassword: $this->stringValue($data, ConfigKey::SqlPassword),
            challengeTokenTtlSeconds: $this->intValue($data, ConfigKey::ChallengeTokenTtlSeconds),
            reportApiKey: $this->stringValue($data, ConfigKey::ReportApiKey),
            reportCorsAllowedOrigin: $this->stringValue($data, ConfigKey::ReportCorsAllowedOrigin),
        );
    }

    /** @param array<string, mixed> $data */
    private function boolValue(array $data, ConfigKey $key): bool
    {
        $value = $data[$key->value] ?? $key->default();
        if (!is_bool($value)) {
            throw new RuntimeException(sprintf('Config key "%s" must be a boolean.', $key->value));
        }

        return $value;
    }

    // A JSON whole number decodes as a PHP int, so a float-typed key accepts either and casts to float.
    /** @param array<string, mixed> $data */
    private function floatValue(array $data, ConfigKey $key): float
    {
        $value = $data[$key->value] ?? $key->default();
        if (!is_float($value) && !is_int($value)) {
            throw new RuntimeException(sprintf('Config key "%s" must be a number.', $key->value));
        }

        return (float) $value;
    }

    /**
     * @param array<string, mixed> $data
     * @return array<int, int>
     */
    private function intListValue(array $data, ConfigKey $key): array
    {
        $value = $data[$key->value] ?? $key->default();
        if (!is_array($value)) {
            throw new RuntimeException(sprintf('Config key "%s" must be an array.', $key->value));
        }

        $ints = [];
        foreach ($value as $item) {
            if (!is_int($item)) {
                throw new RuntimeException(sprintf('Config key "%s" must be an array of integers.', $key->value));
            }

            $ints[] = $item;
        }

        return $ints;
    }

    /** @param array<string, mixed> $data */
    private function intValue(array $data, ConfigKey $key): int
    {
        $value = $data[$key->value] ?? $key->default();
        if (!is_int($value)) {
            throw new RuntimeException(sprintf('Config key "%s" must be an integer.', $key->value));
        }

        return $value;
    }

    // A missing config file is valid (every key then falls back to its ConfigKey default).
    /** @return array<string, mixed> */
    private function readJsonFile(string $path): array
    {
        if (!is_file($path)) {
            return [];
        }

        $contents = file_get_contents($path);
        if ($contents === false) {
            throw new RuntimeException(sprintf('Unable to read config file: %s', $path));
        }

        $decoded = json_decode($contents, true);
        if (!is_array($decoded)) {
            throw new RuntimeException(sprintf('Config file is not a valid JSON object: %s', $path));
        }

        return $decoded;
    }

    // Like stringValue(), but additionally rejects a value outside the key's own allowedStrings().
    /** @param array<string, mixed> $data */
    private function stringEnumValue(array $data, ConfigKey $key): string
    {
        $value = $this->stringValue($data, $key);
        if (!in_array($value, $key->allowedStrings(), true)) {
            throw new RuntimeException(sprintf('Config key "%s" must be one of: %s.', $key->value, implode(', ', $key->allowedStrings())));
        }

        return $value;
    }

    /**
     * @param array<string, mixed> $data
     * @return array<int, string>
     */
    private function stringListValue(array $data, ConfigKey $key): array
    {
        $value = $data[$key->value] ?? $key->default();
        if (!is_array($value)) {
            throw new RuntimeException(sprintf('Config key "%s" must be an array.', $key->value));
        }

        $strings = [];
        foreach ($value as $item) {
            if (!is_string($item)) {
                throw new RuntimeException(sprintf('Config key "%s" must be an array of strings.', $key->value));
            }

            $strings[] = $item;
        }

        return $strings;
    }

    /** @param array<string, mixed> $data */
    private function stringValue(array $data, ConfigKey $key): string
    {
        $value = $data[$key->value] ?? $key->default();
        if (!is_string($value)) {
            throw new RuntimeException(sprintf('Config key "%s" must be a string.', $key->value));
        }

        return $value;
    }
}

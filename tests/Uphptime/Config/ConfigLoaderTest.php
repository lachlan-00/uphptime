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

namespace Uphptime\Tests\Config;

use PHPUnit\Framework\TestCase;
use RuntimeException;
use Uphptime\Config\ConfigLoader;
use Uphptime\Version;

final class ConfigLoaderTest extends TestCase
{
    private string $tempFile;

    public function testExplicitFalseBooleanOverridesTheDefault(): void
    {
        file_put_contents($this->tempFile, json_encode(['pinger_enabled' => false]));

        $config = (new ConfigLoader())->load($this->tempFile);

        self::assertFalse($config->pingerEnabled);
    }

    public function testExplicitFloatOverridesTheDefault(): void
    {
        file_put_contents($this->tempFile, json_encode(['canary_sample_rate' => 0.5]));

        $config = (new ConfigLoader())->load($this->tempFile);

        self::assertSame(0.5, $config->canarySampleRate);
    }

    public function testExplicitListsOverrideTheirDefaults(): void
    {
        file_put_contents($this->tempFile, json_encode([
            'tcp_port_allowlist' => [22, 443],
            'egress_allowed_ranges' => ['10.0.0.0/8'],
        ]));

        $config = (new ConfigLoader())->load($this->tempFile);

        self::assertSame([22, 443], $config->tcpPortAllowlist);
        self::assertSame(['10.0.0.0/8'], $config->egressAllowedRanges);
    }

    public function testExplicitRedirectLimitOverridesTheDefault(): void
    {
        file_put_contents($this->tempFile, json_encode(['max_redirects' => 3]));

        $config = (new ConfigLoader())->load($this->tempFile);

        self::assertSame(3, $config->maxRedirects);
    }

    public function testExplicitValuesOverrideDefaults(): void
    {
        file_put_contents($this->tempFile, json_encode([
            'check_interval_seconds' => 60,
            'timezone' => 'Australia/Sydney',
        ]));

        $config = (new ConfigLoader())->load($this->tempFile);

        self::assertSame(60, $config->checkIntervalSeconds);
        self::assertSame('Australia/Sydney', $config->timezone);
        self::assertSame(90, $config->retentionDays, 'omitted keys still fall back to their default');
    }

    public function testMissingFileUsesAllDefaults(): void
    {
        unlink($this->tempFile);

        $config = (new ConfigLoader())->load($this->tempFile);

        self::assertSame('data', $config->dataDirectory);
        self::assertSame(300, $config->checkIntervalSeconds);
        self::assertSame(90, $config->retentionDays);
        self::assertSame('UTC', $config->timezone);
        self::assertSame(Version::USER_AGENT, $config->httpUserAgent);
        self::assertTrue($config->pingerEnabled);
        self::assertSame(0, $config->maxRedirects);
        self::assertSame(60, $config->clockSkewSeconds);
        self::assertNotEmpty($config->tcpPortAllowlist);
        self::assertSame([], $config->egressAllowedRanges);
        self::assertSame(3, $config->maxDelegatesPerAcquisition);
        self::assertTrue($config->localFailureCountsAsDown);
        self::assertSame(0.07, $config->canarySampleRate);
        self::assertSame(100.0, $config->peerReputationNeutralScore);
        self::assertSame(40.0, $config->peerReputationExclusionThreshold);
        self::assertSame(45.0, $config->peerReputationProbationScore);
    }

    public function testNonArrayTcpPortAllowlistThrows(): void
    {
        file_put_contents($this->tempFile, json_encode(['tcp_port_allowlist' => 'not-an-array']));

        $this->expectException(RuntimeException::class);
        (new ConfigLoader())->load($this->tempFile);
    }

    public function testNonBooleanPingerEnabledThrows(): void
    {
        file_put_contents($this->tempFile, json_encode(['pinger_enabled' => 'yes']));

        $this->expectException(RuntimeException::class);
        (new ConfigLoader())->load($this->tempFile);
    }

    public function testNonNumericFloatKeyThrows(): void
    {
        file_put_contents($this->tempFile, json_encode(['canary_sample_rate' => 'not-a-number']));

        $this->expectException(RuntimeException::class);
        (new ConfigLoader())->load($this->tempFile);
    }

    public function testPreviousDefaultUserAgentsUseCurrentVersion(): void
    {
        foreach (['uPHPtime/0.1', 'uPHPtime/0.1 (+https://github.com/lachlan-00/uphptime)', ''] as $agent) {
            file_put_contents($this->tempFile, json_encode(['http_user_agent' => $agent]));
            self::assertSame(Version::USER_AGENT, (new ConfigLoader())->load($this->tempFile)->httpUserAgent);
        }
    }

    public function testUnknownKeysAreIgnored(): void
    {
        file_put_contents($this->tempFile, json_encode(['not_a_real_key' => 'value']));

        $config = (new ConfigLoader())->load($this->tempFile);

        self::assertSame(300, $config->checkIntervalSeconds);
    }

    public function testUserAgentIncludesVersionAndPreservesCustomText(): void
    {
        file_put_contents($this->tempFile, json_encode(['http_user_agent' => 'my-monitor']));

        self::assertSame(Version::USER_AGENT . ' my-monitor', (new ConfigLoader())->load($this->tempFile)->httpUserAgent);
    }

    public function testWholeNumberJsonValueIsAcceptedForAFloatKey(): void
    {
        file_put_contents($this->tempFile, json_encode(['canary_sample_rate' => 1]));

        $config = (new ConfigLoader())->load($this->tempFile);

        self::assertSame(1.0, $config->canarySampleRate, 'a JSON whole number decodes as a PHP int and must still be accepted');
    }

    public function testWrongTypeThrows(): void
    {
        file_put_contents($this->tempFile, json_encode(['check_interval_seconds' => 'not-an-int']));

        $this->expectException(RuntimeException::class);
        (new ConfigLoader())->load($this->tempFile);
    }

    protected function setUp(): void
    {
        $this->tempFile = tempnam(sys_get_temp_dir(), 'uphptime-config-');
    }

    protected function tearDown(): void
    {
        @unlink($this->tempFile);
    }
}

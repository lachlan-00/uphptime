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

namespace Uphptime\Tests\Pinger\Cache;

use DateTimeImmutable;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use Uphptime\Check\CheckOutcome;
use Uphptime\Check\CheckResult;
use Uphptime\Pinger\Cache\CacheRefresher;
use Uphptime\Pinger\Cache\CheckCacheEntry;
use Uphptime\Pinger\DelegatedCheckOutcome;
use Uphptime\Site\CheckType;
use Uphptime\Site\Site;
use Uphptime\Site\SiteRuntimeState;
use Uphptime\Site\SiteState;
use Uphptime\Support\AtomicWriter;

final class CacheRefresherTest extends TestCase
{
    private string $directory;

    // The property the whole design exists for: a slow probe triggered one way and a concurrent call
    // for the identical fingerprint triggered the other way can never both probe - whichever gets the
    // lock first does, and the other waits and reads that result instead of starting its own.
    public function testConcurrentCallsForTheSameFingerprintAcrossBothTriggersResultInExactlyOneProbe(): void
    {
        $probeLog   = $this->directory . '/probe-log.txt';
        $outputFile = fn(string $label): string => $this->directory . '/out-' . $label . '.json';

        $script = $this->directory . '/refresh-' . bin2hex(random_bytes(4)) . '.php';
        file_put_contents($script, <<<'PHP'
            <?php
            [, $autoload, $cacheDir, $probeLog, $outFile, $requestedAtArg] = $argv;
            require $autoload;

            $writer    = new Uphptime\Support\AtomicWriter();
            $refresher = new Uphptime\Pinger\Cache\CacheRefresher($writer, $cacheDir);
            $now       = new DateTimeImmutable('2026-01-01T00:00:00+00:00');
            $site      = new Uphptime\Site\Site(
                's', 's', 'http://example.test/', null, null,
                Uphptime\Site\CheckType::Http, null, null, null, true, $now,
                new Uphptime\Site\SiteRuntimeState(Uphptime\Site\SiteState::Unknown, 0, null, null, $now, null),
            );

            $requestedAt = $requestedAtArg === 'null' ? null : $now;

            $entry = $refresher->getOrRefresh($site, $now, 60, function (Uphptime\Pinger\Cache\CheckCacheEntry $e, DateTimeImmutable $n): bool {
                return !$e->isExpired($n);
            }, function () use ($probeLog): Uphptime\Pinger\DelegatedCheckOutcome {
                usleep(400_000);
                file_put_contents($probeLog, "1\n", FILE_APPEND);

                return new Uphptime\Pinger\DelegatedCheckOutcome(Uphptime\Check\CheckResult::pass(200, 10), ['resolved_ip' => '93.184.216.34']);
            }, $requestedAt);

            file_put_contents($outFile, json_encode(['probed_at' => $entry->probedAt->format(DATE_ATOM)]));
            PHP);

        $autoload = dirname(__DIR__, 4) . '/vendor/autoload.php';

        // One call styled as the proactive cron (requestedAt = null), one styled as a reactive
        // request (requestedAt = now) - launched together, for the identical fingerprint.
        $processes     = [];
        $pipesByLabel  = [];
        foreach (['cron' => 'null', 'reactive' => 'now'] as $label => $requestedAtArg) {
            $processes[$label] = proc_open(
                [PHP_BINARY, $script, $autoload, $this->directory, $probeLog, $outputFile($label), $requestedAtArg],
                [1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
                $pipesByLabel[$label],
            );
        }

        $errors = [];
        foreach ($processes as $label => $process) {
            $errors[$label] = stream_get_contents($pipesByLabel[$label][2]) ?: '';
            proc_close($process);
        }

        @unlink($script);

        self::assertFileExists($outputFile('cron'), implode("\n", $errors));
        self::assertFileExists($outputFile('reactive'), implode("\n", $errors));

        $probedAtCron     = json_decode((string) file_get_contents($outputFile('cron')), true)['probed_at'];
        $probedAtReactive = json_decode((string) file_get_contents($outputFile('reactive')), true)['probed_at'];

        self::assertSame($probedAtCron, $probedAtReactive, 'both callers must observe the same single probe result');
        self::assertSame("1\n", file_get_contents($probeLog), 'the probe must have run exactly once across both processes');
    }

    public function testCronIsFreshCallbackHonorsTheLeadWindowNotJustHardExpiry(): void
    {
        $refresher = new CacheRefresher(new AtomicWriter(), $this->directory);
        $site      = $this->site();
        $probed    = 0;

        $refresher->getOrRefresh($site, new DateTimeImmutable('2026-01-01T00:00:00+00:00'), 60, static fn(): bool => false, function () use (&$probed): DelegatedCheckOutcome {
            $probed++;

            return new DelegatedCheckOutcome(CheckResult::pass(200, 10), ['resolved_ip' => '93.184.216.34']);
        }, new DateTimeImmutable('2026-01-01T00:00:00+00:00'));

        // Reactive isFreshCallback: not expired yet at +30s (TTL 60s) -> no second probe.
        $refresher->getOrRefresh($site, new DateTimeImmutable('2026-01-01T00:00:30+00:00'), 60, static fn(CheckCacheEntry $e, DateTimeImmutable $n): bool => !$e->isExpired($n), static fn(): DelegatedCheckOutcome => throw new RuntimeException('should not probe'), new DateTimeImmutable('2026-01-01T00:00:30+00:00'));

        // Cron isFreshCallback: inside the 10s lead window before expiry -> refresh even though not yet expired.
        $entry = $refresher->getOrRefresh(
            $site,
            new DateTimeImmutable('2026-01-01T00:00:55+00:00'),
            60,
            static fn(CheckCacheEntry $e, DateTimeImmutable $n): bool => $n < $e->expiresAt->modify('-10 seconds'),
            function () use (&$probed): DelegatedCheckOutcome {
                $probed++;

                return new DelegatedCheckOutcome(CheckResult::pass(200, 11), ['resolved_ip' => '93.184.216.34']);
            },
        );

        self::assertSame(2, $probed);
        self::assertSame('2026-01-01T00:00:55+00:00', $entry->probedAt->format(DATE_ATOM));
    }

    public function testNonCacheableErrorOutcomeIsReturnedButNeverPersisted(): void
    {
        $refresher = new CacheRefresher(new AtomicWriter(), $this->directory);
        $site      = $this->site();
        $now       = new DateTimeImmutable('2026-01-01T00:00:00+00:00');

        $entry = $refresher->getOrRefresh($site, $now, 60, static fn(): bool => false, static fn(): DelegatedCheckOutcome => new DelegatedCheckOutcome(CheckResult::error('target_not_allowed_for_peer'), null), $now);

        self::assertSame(CheckOutcome::Error, $entry->result->outcome);
        self::assertSame([], glob($this->directory . '/*.json') ?: [], 'an Error outcome must never create a cache file');
    }

    public function testReactiveHitBumpsLastRequestedAtButCronRefreshPreservesIt(): void
    {
        $refresher = new CacheRefresher(new AtomicWriter(), $this->directory);
        $site      = $this->site();

        $refresher->getOrRefresh(
            $site,
            new DateTimeImmutable('2026-01-01T00:00:00+00:00'),
            60,
            static fn(): bool => false,
            static fn(): DelegatedCheckOutcome => new DelegatedCheckOutcome(CheckResult::pass(200, 10), ['resolved_ip' => '93.184.216.34']),
            new DateTimeImmutable('2026-01-01T00:00:00+00:00'),
        );

        // Cron-triggered refresh: requestedAt is null, so last_requested_at must stay at the original moment.
        $entry = $refresher->getOrRefresh(
            $site,
            new DateTimeImmutable('2026-01-01T00:00:55+00:00'),
            60,
            static fn(CheckCacheEntry $e, DateTimeImmutable $n): bool => $n < $e->expiresAt->modify('-10 seconds'),
            static fn(): DelegatedCheckOutcome => new DelegatedCheckOutcome(CheckResult::pass(200, 11), ['resolved_ip' => '93.184.216.34']),
        );

        self::assertSame('2026-01-01T00:00:00+00:00', $entry->lastRequestedAt->format(DATE_ATOM));
    }

    protected function setUp(): void
    {
        $this->directory = sys_get_temp_dir() . '/uphptime-cache-refresher-' . bin2hex(random_bytes(4));
        mkdir($this->directory, 0775, true);
    }

    protected function tearDown(): void
    {
        foreach (glob($this->directory . '/*') ?: [] as $file) {
            @unlink($file);
        }
        @rmdir($this->directory);
    }

    private function site(): Site
    {
        $now = new DateTimeImmutable('2026-01-01T00:00:00+00:00');

        return new Site('s', 's', 'http://example.test/', null, null, CheckType::Http, null, null, null, true, $now, new SiteRuntimeState(SiteState::Unknown, 0, null, null, $now, null));
    }
}

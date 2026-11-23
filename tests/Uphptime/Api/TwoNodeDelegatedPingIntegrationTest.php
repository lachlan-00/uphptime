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

namespace Uphptime\Tests\Api;

use DateTimeImmutable;
use PHPUnit\Framework\TestCase;
use Uphptime\Check\CheckOutcome;
use Uphptime\Check\CheckRunner;
use Uphptime\Site\CheckType;
use Uphptime\Site\Site;
use Uphptime\Site\SiteRuntimeState;
use Uphptime\Site\SiteState;
use Uphptime\Storage\HistoryRepositoryInterface;
use Uphptime\Storage\SiteRepositoryInterface;
use Uphptime\Support\ClockInterface;
use Uphptime\Tests\Support\SpawnedPhpServer;
use Uphptime\Tests\Support\TwoNodeHarness;

/**
 * The full chain over real sockets: owner (in-process) -> spawned pinger (real public/index.php) ->
 * spawned "monitored site". Only the owner's role is collapsed in-process, as it is in production.
 */
final class TwoNodeDelegatedPingIntegrationTest extends TestCase
{
    private static SpawnedPhpServer $siteServer;
    private TwoNodeHarness $harness;

    public static function setUpBeforeClass(): void
    {
        $docRoot = sys_get_temp_dir() . '/uphptime-monitored-site-' . bin2hex(random_bytes(4));
        mkdir($docRoot, 0775, true);
        file_put_contents($docRoot . '/index.php', "<?php echo 'monitored-site-ok';");
        self::$siteServer = new SpawnedPhpServer($docRoot);
    }

    public static function tearDownAfterClass(): void
    {
        self::$siteServer->stop();
    }

    public function testFullHappyPathWritesVerifiedHistoryWithEvidence(): void
    {
        $container         = $this->harness->ownerContainer();
        $siteRepository    = $container->get(SiteRepositoryInterface::class);
        $historyRepository = $container->get(HistoryRepositoryInterface::class);
        $clock             = $container->get(ClockInterface::class);

        $siteRepository->save($this->monitoredSite($clock->now()));
        $container->get(CheckRunner::class)->run();

        $records = $historyRepository->readDay('site-1', $clock->now()->format('Y-m-d'));
        self::assertCount(1, $records);
        self::assertSame(CheckOutcome::Pass, $records[0]->outcome);
        self::assertStringStartsWith('peer:', $records[0]->source);
        self::assertSame('evidence', $records[0]->attestation, 'evidence was collected but no challenge token was configured for this site');
        self::assertIsArray($records[0]->evidence);
        self::assertArrayHasKey('resolved_ip', $records[0]->evidence);

        $updated = $siteRepository->find('site-1');
        self::assertNotNull($updated);
        self::assertSame(SiteState::Up, $updated->state->status);
    }

    // Uses a test-local site server (not the shared static one) so it can be stopped without affecting other tests.
    public function testPingerAndSiteBothDownRecordsAFailureViaTheSelfCheckFallback(): void
    {
        $docRoot = sys_get_temp_dir() . '/uphptime-monitored-site-' . bin2hex(random_bytes(4));
        mkdir($docRoot, 0775, true);
        file_put_contents($docRoot . '/index.php', "<?php echo 'monitored-site-ok';");
        $siteServer = new SpawnedPhpServer($docRoot);

        try {
            $container         = $this->harness->ownerContainer();
            $siteRepository    = $container->get(SiteRepositoryInterface::class);
            $historyRepository = $container->get(HistoryRepositoryInterface::class);
            $clock             = $container->get(ClockInterface::class);

            $siteRepository->save($this->monitoredSite($clock->now(), $siteServer));
            $this->harness->stopPinger();
            $siteServer->stop();

            $container->get(CheckRunner::class)->run();

            $records = $historyRepository->readDay('site-1', $clock->now()->format('Y-m-d'));
            self::assertCount(1, $records);
            self::assertSame(CheckOutcome::Fail, $records[0]->outcome);
            self::assertSame('self', $records[0]->source);

            $updated = $siteRepository->find('site-1');
            self::assertNotNull($updated);
            self::assertSame(SiteState::Suspect, $updated->state->status);
            self::assertSame(1, $updated->state->consecutiveFails);
        } finally {
            $siteServer->stop();
        }
    }

    // An unresponsive peer falls back to a self-check rather than recording an unmeasured cycle; the site is genuinely up here.
    public function testPingerDownFallsBackToASelfCheckAgainstTheStillUpSite(): void
    {
        $container         = $this->harness->ownerContainer();
        $siteRepository    = $container->get(SiteRepositoryInterface::class);
        $historyRepository = $container->get(HistoryRepositoryInterface::class);
        $clock             = $container->get(ClockInterface::class);

        $siteRepository->save($this->monitoredSite($clock->now()));
        $this->harness->stopPinger();

        $container->get(CheckRunner::class)->run();

        $records = $historyRepository->readDay('site-1', $clock->now()->format('Y-m-d'));
        self::assertCount(1, $records);
        self::assertSame(CheckOutcome::Pass, $records[0]->outcome);
        self::assertSame('self', $records[0]->source);

        $updated = $siteRepository->find('site-1');
        self::assertNotNull($updated);
        self::assertSame(SiteState::Up, $updated->state->status);
        self::assertSame(0, $updated->state->consecutiveFails);
    }

    protected function setUp(): void
    {
        $this->harness = new TwoNodeHarness();
    }

    protected function tearDown(): void
    {
        $this->harness->stopPinger();
        $this->harness->cleanup();
    }

    private function monitoredSite(DateTimeImmutable $now, ?SpawnedPhpServer $siteServer = null): Site
    {
        return new Site(
            'site-1',
            'Monitored site',
            ($siteServer ?? self::$siteServer)->baseUrl() . '/',
            null,
            null,
            CheckType::Http,
            null,
            null,
            null,
            true,
            $now,
            new SiteRuntimeState(SiteState::Unknown, 0, null, null, $now, null),
        );
    }
}

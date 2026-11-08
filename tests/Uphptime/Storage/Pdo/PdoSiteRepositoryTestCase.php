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

namespace Uphptime\Tests\Storage\Pdo;

use DateTimeImmutable;
use PDO;
use PHPUnit\Framework\TestCase;
use Uphptime\Site\ChallengeDelivery;
use Uphptime\Site\CheckType;
use Uphptime\Site\KeywordMode;
use Uphptime\Site\MaintenanceWindow;
use Uphptime\Site\Site;
use Uphptime\Site\SiteRuntimeState;
use Uphptime\Site\SiteState;
use Uphptime\Storage\Pdo\PdoSiteRepository;

/**
 * Dialect-agnostic coverage for PdoSiteRepository, run against both real engines by the two
 * one-line concrete subclasses. Skips cleanly (not a failure) when its container isn't reachable.
 */
abstract class PdoSiteRepositoryTestCase extends TestCase
{
    use PdoRepositoryTestHelpers;

    private PDO $pdo;

    public function testAddListRemoveRoundTrip(): void
    {
        $repository = $this->repository();

        $repository->save($this->site('a'));
        $repository->save($this->site('b'));

        self::assertCount(2, $repository->all());
        self::assertNotNull($repository->find('a'));

        $repository->remove('a');

        self::assertCount(1, $repository->all());
        self::assertNull($repository->find('a'));
    }

    public function testChallengeDeliveryRoundTrips(): void
    {
        $repository = $this->repository();

        $repository->save($this->site('a')->withChallengeDelivery(ChallengeDelivery::Header));

        $found = $repository->find('a');
        self::assertNotNull($found);
        self::assertSame(ChallengeDelivery::Header, $found->challengeDelivery);

        $repository->save($found->withChallengeDelivery(null));

        $resaved = $repository->find('a');
        self::assertNotNull($resaved);
        self::assertNull($resaved->challengeDelivery);
    }

    public function testConcurrentWritersForDifferentSitesBothPersist(): void
    {
        $repository = $this->repository();
        $repository->save($this->site('seed'));

        $scripts   = [];
        $processes = [];
        foreach (['x', 'y'] as $id) {
            [$process, $script] = $this->spawnSiteWriter($id);
            $processes[]        = $process;
            $scripts[]          = $script;
        }

        foreach ($processes as $process) {
            proc_close($process);
        }

        foreach ($scripts as $script) {
            @unlink($script);
        }

        $ids = array_map(static fn(Site $site): string => $site->id, $repository->all());
        sort($ids);

        self::assertSame(['seed', 'x', 'y'], $ids);
    }

    // A fully-populated site, including every nullable field set, round-trips with no loss of data.
    public function testFullFieldRoundTripWithEveryNullableFieldSet(): void
    {
        $repository = $this->repository();
        $now        = new DateTimeImmutable('2026-01-01T00:00:00+00:00');

        $site = new Site(
            id: 'full',
            name: 'Full example',
            url: null,
            host: 'mail.example.test',
            port: 25,
            checkType: CheckType::Tcp,
            keyword: 'Welcome',
            keywordMode: KeywordMode::Contains,
            checkIntervalSeconds: 120,
            enabled: false,
            createdAt: $now,
            state: new SiteRuntimeState(
                status: SiteState::Suspect,
                consecutiveFails: 2,
                lastOutcome: \Uphptime\Check\CheckOutcome::Fail,
                lastCheckedAt: $now->modify('+5 minutes'),
                nextDueAt: $now->modify('+10 minutes'),
                lastTransitionAt: $now->modify('+5 minutes'),
            ),
        );

        $repository->save($site);
        $found = $repository->find('full');

        self::assertNotNull($found);
        self::assertSame('mail.example.test', $found->host);
        self::assertSame(25, $found->port);
        self::assertSame(CheckType::Tcp, $found->checkType);
        self::assertSame('Welcome', $found->keyword);
        self::assertSame(KeywordMode::Contains, $found->keywordMode);
        self::assertSame(120, $found->checkIntervalSeconds);
        self::assertFalse($found->enabled);
        self::assertSame(SiteState::Suspect, $found->state->status);
        self::assertSame(2, $found->state->consecutiveFails);
        self::assertNotNull($found->state->lastOutcome);
        self::assertNotNull($found->state->lastCheckedAt);
        self::assertNotNull($found->state->lastTransitionAt);
    }

    public function testGroupsRoundTripAndAreReplacedOnResave(): void
    {
        $repository = $this->repository();

        $repository->save($this->site('a')->withGroups(['prod', 'web']));

        $found = $repository->find('a');
        self::assertNotNull($found);
        self::assertSame(['prod', 'web'], $found->groups);

        $repository->save($found->withGroups(['staging']));

        $resaved = $repository->find('a');
        self::assertNotNull($resaved);
        self::assertSame(['staging'], $resaved->groups);
    }

    // Maintenance windows live in a child table and must survive a save/find round trip, including
    // a resave that replaces the window set entirely rather than appending to it.
    public function testMaintenanceWindowsRoundTripAndAreReplacedOnResave(): void
    {
        $repository = $this->repository();
        $now        = new DateTimeImmutable('2026-01-01T00:00:00+00:00');
        $window     = new MaintenanceWindow(
            id: 'deadbeef',
            startsAt: $now->modify('+2 hours'),
            endsAt: $now->modify('+4 hours'),
            note: 'Planned deploy',
        );

        $repository->save($this->site('a')->withMaintenanceWindows([$window]));

        $found = $repository->find('a');
        self::assertNotNull($found);
        self::assertCount(1, $found->maintenanceWindows);
        self::assertSame('deadbeef', $found->maintenanceWindows[0]->id);
        self::assertSame('Planned deploy', $found->maintenanceWindows[0]->note);

        $repository->save($found->withMaintenanceWindows([]));

        $resaved = $repository->find('a');
        self::assertNotNull($resaved);
        self::assertSame([], $resaved->maintenanceWindows);
    }

    public function testNextDueAtOrderingSupportsDueSiteSelection(): void
    {
        $repository = $this->repository();
        $base       = new DateTimeImmutable('2026-01-01T00:00:00+00:00');

        $repository->save($this->site('late', $base->modify('+30 minutes')));
        $repository->save($this->site('soon', $base->modify('+1 minute')));
        $repository->save($this->site('middle', $base->modify('+10 minutes')));

        $ids = array_map(static fn(Site $site): string => $site->id, $repository->all());

        self::assertSame(['soon', 'middle', 'late'], $ids);
    }

    public function testSaveOnExistingIdReplacesRatherThanDuplicates(): void
    {
        $repository = $this->repository();

        $repository->save($this->site('a'));
        $repository->save($this->site('a')->withState(
            new SiteRuntimeState(SiteState::Up, 0, null, null, new DateTimeImmutable('2026-01-01T00:05:00+00:00'), null),
        ));

        self::assertCount(1, $repository->all());

        $found = $repository->find('a');
        self::assertNotNull($found);
        self::assertSame(SiteState::Up, $found->state->status);
    }

    abstract protected function dialectName(): string;

    protected function setUp(): void
    {
        $this->pdo = $this->connectOrSkip($this->dialectName());
    }

    private function repository(): PdoSiteRepository
    {
        return new PdoSiteRepository($this->pdo, $this->dialectFor($this->dialectName()));
    }

    private function site(string $id, ?DateTimeImmutable $nextDueAt = null): Site
    {
        $now = new DateTimeImmutable('2026-01-01T00:00:00+00:00');

        return new Site(
            id: $id,
            name: 'Example ' . $id,
            url: 'https://example.test/',
            host: null,
            port: null,
            checkType: CheckType::Http,
            keyword: null,
            keywordMode: null,
            checkIntervalSeconds: null,
            enabled: true,
            createdAt: $now,
            state: new SiteRuntimeState(SiteState::Unknown, 0, null, null, $nextDueAt ?? $now, null),
        );
    }

    /** @return array{0: resource, 1: string} */
    private function spawnSiteWriter(string $id): array
    {
        $script = sys_get_temp_dir() . '/uphptime-pdo-site-writer-' . bin2hex(random_bytes(4)) . '.php';
        file_put_contents($script, <<<'PHP'
            <?php
            require $argv[2];
            $dialect = $argv[3] === 'pgsql' ? new Uphptime\Storage\Pdo\PostgreSqlDialect() : new Uphptime\Storage\Pdo\MySqlDialect();
            $pdo = Uphptime\Tests\Storage\Pdo\PdoTestConnection::attempt($argv[3]);
            $repo = new Uphptime\Storage\Pdo\PdoSiteRepository($pdo, $dialect);
            $now = new DateTimeImmutable('2026-01-01T00:00:00+00:00');
            $site = new Uphptime\Site\Site(
                $argv[1], 'concurrent', 'https://example.test/', null, null,
                Uphptime\Site\CheckType::Http, null, null, null, true, $now,
                new Uphptime\Site\SiteRuntimeState(Uphptime\Site\SiteState::Unknown, 0, null, null, $now, null),
            );
            $repo->save($site);
            PHP);

        $autoload = dirname(__DIR__, 4) . '/vendor/autoload.php';
        $process  = proc_open(
            [PHP_BINARY, $script, $id, $autoload, $this->dialectName()],
            [1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $pipes,
        );

        return [$process, $script];
    }
}

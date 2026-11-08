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

namespace Uphptime\Tests\Storage;

use DateTimeImmutable;
use PHPUnit\Framework\TestCase;
use Uphptime\Site\ChallengeDelivery;
use Uphptime\Site\CheckType;
use Uphptime\Site\MaintenanceWindow;
use Uphptime\Site\Site;
use Uphptime\Site\SiteRuntimeState;
use Uphptime\Site\SiteState;
use Uphptime\Storage\JsonSiteRepository;
use Uphptime\Support\AtomicWriter;

final class JsonSiteRepositoryTest extends TestCase
{
    private string $path;

    public function testAddListRemoveRoundTrip(): void
    {
        $repository = new JsonSiteRepository(new AtomicWriter(), $this->path);

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
        $repository = new JsonSiteRepository(new AtomicWriter(), $this->path);

        $repository->save($this->site('a')->withChallengeDelivery(ChallengeDelivery::WellKnown));

        $found = $repository->find('a');
        self::assertNotNull($found);
        self::assertSame(ChallengeDelivery::WellKnown, $found->challengeDelivery);
    }

    public function testConcurrentWritersForDifferentSitesBothPersist(): void
    {
        $repository = new JsonSiteRepository(new AtomicWriter(), $this->path);
        $repository->save($this->site('seed'));

        $script = sys_get_temp_dir() . '/uphptime-site-writer-' . bin2hex(random_bytes(4)) . '.php';
        file_put_contents($script, <<<'PHP'
            <?php
            require $argv[2];
            $repo = new Uphptime\Storage\JsonSiteRepository(new Uphptime\Support\AtomicWriter(), $argv[1]);
            $now = new DateTimeImmutable('2026-01-01T00:00:00+00:00');
            $site = new Uphptime\Site\Site(
                $argv[3], 'concurrent', 'https://example.test/', null, null,
                Uphptime\Site\CheckType::Http, null, null, null, true, $now,
                new Uphptime\Site\SiteRuntimeState(Uphptime\Site\SiteState::Unknown, 0, null, null, $now, null),
            );
            $repo->save($site);
            PHP);

        $autoload  = dirname(__DIR__, 3) . '/vendor/autoload.php';
        $processes = [];
        foreach (['x', 'y'] as $id) {
            $processes[] = proc_open(
                [PHP_BINARY, $script, $this->path, $autoload, $id],
                [1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
                $pipes,
            );
        }

        foreach ($processes as $process) {
            proc_close($process);
        }
        @unlink($script);

        $ids = array_map(static fn(Site $site): string => $site->id, $repository->all());
        sort($ids);

        self::assertSame(['seed', 'x', 'y'], $ids);
    }

    public function testGroupsRoundTrip(): void
    {
        $repository = new JsonSiteRepository(new AtomicWriter(), $this->path);

        $repository->save($this->site('a')->withGroups(['prod', 'web']));

        $found = $repository->find('a');
        self::assertNotNull($found);
        self::assertSame(['prod', 'web'], $found->groups);
    }

    public function testMaintenanceWindowsRoundTrip(): void
    {
        $repository = new JsonSiteRepository(new AtomicWriter(), $this->path);
        $window     = new MaintenanceWindow(
            id: 'deadbeef',
            startsAt: new DateTimeImmutable('2026-01-01T02:00:00+00:00'),
            endsAt: new DateTimeImmutable('2026-01-01T04:00:00+00:00'),
            note: 'Planned deploy',
        );

        $repository->save($this->site('a')->withMaintenanceWindows([$window]));

        $found = $repository->find('a');
        self::assertNotNull($found);
        self::assertCount(1, $found->maintenanceWindows);
        self::assertSame('deadbeef', $found->maintenanceWindows[0]->id);
        self::assertSame('Planned deploy', $found->maintenanceWindows[0]->note);
        self::assertEquals($window->startsAt, $found->maintenanceWindows[0]->startsAt);
        self::assertEquals($window->endsAt, $found->maintenanceWindows[0]->endsAt);
    }

    public function testSaveOnExistingIdReplacesRatherThanDuplicates(): void
    {
        $repository = new JsonSiteRepository(new AtomicWriter(), $this->path);

        $repository->save($this->site('a'));
        $repository->save($this->site('a')->withState(
            new SiteRuntimeState(SiteState::Up, 0, null, null, new DateTimeImmutable('2026-01-01T00:05:00+00:00'), null),
        ));

        self::assertCount(1, $repository->all());

        $found = $repository->find('a');
        self::assertNotNull($found);
        self::assertSame(SiteState::Up, $found->state->status);
    }

    protected function setUp(): void
    {
        $this->path = sys_get_temp_dir() . '/uphptime-sites-' . bin2hex(random_bytes(4)) . '.json';
    }

    protected function tearDown(): void
    {
        @unlink($this->path);
        @unlink($this->path . '.lock');
    }

    private function site(string $id): Site
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
            state: new SiteRuntimeState(SiteState::Unknown, 0, null, null, $now, null),
        );
    }
}

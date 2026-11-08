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

namespace Uphptime\Tests\Storage\Portability;

use DateTimeImmutable;
use PHPUnit\Framework\TestCase;
use Uphptime\Check\CheckOutcome;
use Uphptime\History\DailyRollup;
use Uphptime\History\HistoryRecord;
use Uphptime\Peer\Peer;
use Uphptime\Peer\PeerKey;
use Uphptime\Peer\PeerReputation;
use Uphptime\Site\CheckType;
use Uphptime\Site\Site;
use Uphptime\Site\SiteRuntimeState;
use Uphptime\Site\SiteState;
use Uphptime\Storage\Portability\StorageRepositorySet;
use Uphptime\Storage\Portability\StorageWiper;
use Uphptime\Tests\Storage\InMemoryHistoryRepository;
use Uphptime\Tests\Storage\InMemoryPeerRepository;
use Uphptime\Tests\Storage\InMemoryPeerReputationRepository;
use Uphptime\Tests\Storage\InMemoryRollupRepository;
use Uphptime\Tests\Storage\InMemorySiteRepository;

final class StorageWiperTest extends TestCase
{
    public function testEmptiesEveryRepositoryIncludingOrphanedReputationAndHistory(): void
    {
        $now = new DateTimeImmutable('2026-01-01T00:00:00+00:00');

        $sites = new InMemorySiteRepository();
        $sites->save(new Site(
            id: 'site-a',
            name: 'Example',
            url: 'https://example.test/',
            host: null,
            port: null,
            checkType: CheckType::Http,
            keyword: null,
            keywordMode: null,
            checkIntervalSeconds: null,
            enabled: true,
            createdAt: $now,
            state: new SiteRuntimeState(SiteState::Up, 0, CheckOutcome::Pass, $now, $now, $now),
        ));

        $peers = new InMemoryPeerRepository();
        $peers->save(new Peer('peer-a', 'Peer A', 'https://peer-a.test/', true, true, null, [], [
            new PeerKey('k1', 'secret', true, true, true, $now),
        ]));

        $reputations = new InMemoryPeerReputationRepository();
        $reputations->save(new PeerReputation('peer-a', 72.5, 0, null, $now, 3));
        // An orphaned reputation row for a peer that no longer has a `peers` entry must also be wiped.
        $reputations->save(new PeerReputation('peer-gone', 40.0, 1, null, $now, 5));

        $history = new InMemoryHistoryRepository();
        $history->append('site-a', new HistoryRecord(
            ts: $now,
            siteId: 'site-a',
            checkType: CheckType::Http,
            source: 'self',
            outcome: CheckOutcome::Pass,
            httpStatus: 200,
            responseTimeMs: 10,
            keywordMatched: null,
            error: null,
            stateBefore: SiteState::Unknown,
            stateAfter: SiteState::Up,
            excludedByMaintenance: false,
        ));

        $rollups = new InMemoryRollupRepository();
        $rollups->write(new DailyRollup('site-a', '2026-01-01', 1, 1, 0, 0, 0, 1, 0, 100.0, $now, $now, $now));

        $target = new StorageRepositorySet($sites, $peers, $reputations, $history, $rollups);

        (new StorageWiper())->wipe($target);

        self::assertSame([], $sites->all());
        self::assertSame([], $peers->all());
        self::assertSame([], $reputations->all());
        self::assertSame([], $history->readDay('site-a', '2026-01-01'));
        self::assertNull($rollups->read('site-a', '2026-01-01'));
        self::assertTrue($target->isEmpty());
    }
}

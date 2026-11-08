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
use Uphptime\Site\MaintenanceWindow;
use Uphptime\Site\Site;
use Uphptime\Site\SiteRuntimeState;
use Uphptime\Site\SiteState;
use Uphptime\Storage\Portability\StorageCopier;
use Uphptime\Storage\Portability\StorageRepositorySet;
use Uphptime\Tests\Storage\InMemoryHistoryRepository;
use Uphptime\Tests\Storage\InMemoryPeerRepository;
use Uphptime\Tests\Storage\InMemoryPeerReputationRepository;
use Uphptime\Tests\Storage\InMemoryRollupRepository;
use Uphptime\Tests\Storage\InMemorySiteRepository;

final class StorageCopierTest extends TestCase
{
    public function testCopiesEverythingOntoAnEmptyDestination(): void
    {
        $now = new DateTimeImmutable('2026-01-01T00:00:00+00:00');

        $sourceSites = new InMemorySiteRepository();
        $site        = new Site(
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
            maintenanceWindows: [new MaintenanceWindow('win1', $now, $now->modify('+1 hour'), 'Planned deploy')],
        );
        $sourceSites->save($site);

        $sourcePeers = new InMemoryPeerRepository();
        $peer        = new Peer('peer-a', 'Peer A', 'https://peer-a.test/', true, true, null, ['example.com'], [
            new PeerKey('k1', 'secret', true, true, true, $now),
        ]);
        $sourcePeers->save($peer);

        $sourceReputations = new InMemoryPeerReputationRepository();
        $sourceReputations->save(new PeerReputation('peer-a', 72.5, 0, null, $now, 3));

        $sourceHistory = new InMemoryHistoryRepository();
        $record        = $this->historyRecord($now);
        $sourceHistory->append('site-a', $record);

        $sourceRollups = new InMemoryRollupRepository();
        $rollup        = new DailyRollup('site-a', '2026-01-01', 1, 1, 0, 0, 0, 1, 0, 100.0, $now, $now, $now);
        $sourceRollups->write($rollup);

        $source      = new StorageRepositorySet($sourceSites, $sourcePeers, $sourceReputations, $sourceHistory, $sourceRollups);
        $destination = new StorageRepositorySet(
            new InMemorySiteRepository(),
            new InMemoryPeerRepository(),
            new InMemoryPeerReputationRepository(),
            new InMemoryHistoryRepository(),
            new InMemoryRollupRepository(),
        );

        $summary = (new StorageCopier())->copy($source, $destination);

        self::assertSame(1, $summary->sites);
        self::assertSame(1, $summary->peers);
        self::assertSame(1, $summary->peerReputations);
        self::assertSame(1, $summary->historyRecords);
        self::assertSame(1, $summary->rollups);

        $copiedSite = $destination->sites->find('site-a');
        self::assertNotNull($copiedSite);
        self::assertCount(1, $copiedSite->maintenanceWindows);
        self::assertSame('win1', $copiedSite->maintenanceWindows[0]->id);

        $copiedPeer = $destination->peers->find('peer-a');
        self::assertNotNull($copiedPeer);
        self::assertSame(['example.com'], $copiedPeer->targetAllowlist);
        self::assertCount(1, $copiedPeer->keys);

        self::assertSame(72.5, $destination->peerReputations->get('peer-a')->score);
        self::assertCount(1, $destination->history->readDay('site-a', '2026-01-01'));
        self::assertNotNull($destination->rollups->read('site-a', '2026-01-01'));
    }

    private function historyRecord(DateTimeImmutable $ts): HistoryRecord
    {
        return new HistoryRecord(
            ts: $ts,
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
        );
    }
}

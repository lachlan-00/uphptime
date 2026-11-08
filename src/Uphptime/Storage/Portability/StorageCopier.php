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

namespace Uphptime\Storage\Portability;

/**
 * Copies one full backend's data onto another, purely through the repository interfaces both sides
 * already implement - no serialization code of its own, since a Site/Peer/HistoryRecord/DailyRollup
 * already round-trips through any repository. Sites carry their maintenance windows and peers their
 * keys/allowlist along for free, since those are plain properties on the objects being saved.
 */
final class StorageCopier
{
    public function copy(StorageRepositorySet $source, StorageRepositorySet $destination): StorageCopySummary
    {
        $sites = $source->sites->all();
        foreach ($sites as $site) {
            $destination->sites->save($site);
        }

        $peers = $source->peers->all();
        foreach ($peers as $peer) {
            $destination->peers->save($peer);
        }

        $peerReputations = $source->peerReputations->all();
        foreach ($peerReputations as $reputation) {
            $destination->peerReputations->save($reputation);
        }

        $historyRecords = 0;
        $rollups        = 0;
        foreach ($sites as $site) {
            foreach ($source->history->datesWithHistory($site->id) as $date) {
                foreach ($source->history->readDay($site->id, $date) as $record) {
                    $destination->history->append($site->id, $record);
                    $historyRecords++;
                }
            }

            foreach ($source->rollups->datesWithRollup($site->id) as $date) {
                $rollup = $source->rollups->read($site->id, $date);
                if ($rollup !== null) {
                    $destination->rollups->write($rollup);
                    $rollups++;
                }
            }
        }

        return new StorageCopySummary(count($sites), count($peers), count($peerReputations), $historyRecords, $rollups);
    }
}

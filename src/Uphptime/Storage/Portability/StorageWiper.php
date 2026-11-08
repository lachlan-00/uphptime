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
 * Empties one full backend's data, used only by storage:export/storage:import's --force path. Enumerates
 * sites/peers/reputations up front, since a site's history/rollup days are only found by iterating from it.
 */
final class StorageWiper
{
    public function wipe(StorageRepositorySet $target): void
    {
        $sites           = $target->sites->all();
        $peers           = $target->peers->all();
        $peerReputations = $target->peerReputations->all();

        foreach ($sites as $site) {
            foreach ($target->history->datesWithHistory($site->id) as $date) {
                $target->history->deleteDay($site->id, $date);
            }

            foreach ($target->rollups->datesWithRollup($site->id) as $date) {
                $target->rollups->delete($site->id, $date);
            }

            $target->sites->remove($site->id);
        }

        // Walked independently of $peers - a reputation row has no foreign key to peers and can outlive one.
        foreach ($peerReputations as $reputation) {
            $target->peerReputations->remove($reputation->peerId);
        }

        foreach ($peers as $peer) {
            $target->peers->remove($peer->id);
        }
    }
}

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

namespace Uphptime\Check;

use Uphptime\Config\Config;
use Uphptime\History\HistoryRecord;
use Uphptime\History\HistoryRecordRole;
use Uphptime\Scheduling\RunBudget;
use Uphptime\Site\CheckType;
use Uphptime\Site\Site;
use Uphptime\Site\SiteState;
use Uphptime\Site\SiteStateMachine;
use Uphptime\Storage\HistoryRepositoryInterface;
use Uphptime\Storage\MaintenanceScheduleRepositoryInterface;
use Uphptime\Storage\SiteRepositoryInterface;
use Uphptime\Support\ClockInterface;

/**
 * Rechecks sites whose last check was not a pass, between scheduled passes. A local pass restores the site to up;
 * any other result is only recorded, so downtime can only be declared by the scheduled pass.
 */
final class SiteRechecker
{
    /** @var array<string, CheckerInterface> */
    private readonly array $checkers;

    public function __construct(
        private readonly SiteRepositoryInterface $siteRepository,
        private readonly HistoryRepositoryInterface $historyRepository,
        private readonly MaintenanceScheduleRepositoryInterface $maintenanceScheduleRepository,
        private readonly SiteStateMachine $stateMachine,
        private readonly ClockInterface $clock,
        private readonly Config $config,
        CheckerInterface $httpChecker,
        CheckerInterface $httpKeywordChecker,
        CheckerInterface $tcpChecker,
        CheckerInterface $icmpChecker,
    ) {
        $this->checkers = [
            CheckType::Http->value => $httpChecker,
            CheckType::HttpKeyword->value => $httpKeywordChecker,
            CheckType::Tcp->value => $tcpChecker,
            CheckType::Icmp->value => $icmpChecker,
        ];
    }

    public function run(int $budgetSeconds): RecheckSummary
    {
        $startedAtMs = $this->nowMs();
        $budget      = new RunBudget($this->clock, $budgetSeconds);
        $candidates  = array_values(array_filter($this->siteRepository->all(), $this->needsRecheck(...)));

        $rechecked = 0;
        $recovered = 0;
        $skipped   = 0;

        foreach ($candidates as $site) {
            if ($budget->isExhausted()) {
                $skipped++;
                continue;
            }

            $rechecked++;
            if ($this->recheck($site)) {
                $recovered++;
            }
        }

        return new RecheckSummary(count($candidates), $rechecked, $recovered, $skipped, $this->nowMs() - $startedAtMs);
    }

    // A site is a candidate while it is suspect or down, or its last check was anything but a pass.
    private function needsRecheck(Site $site): bool
    {
        if (!$site->enabled) {
            return false;
        }

        return $site->state->status === SiteState::Suspect
            || $site->state->status === SiteState::Down
            || $site->state->lastOutcome === CheckOutcome::Fail
            || $site->state->lastOutcome === CheckOutcome::Error;
    }

    private function nowMs(): int
    {
        return (int) round($this->clock->now()->format('U.u') * 1000);
    }

    // Returns whether the recheck passed and restored the site.
    private function recheck(Site $site): bool
    {
        $checkedAt = $this->clock->now();

        try {
            $result = $this->checkers[$site->checkType->value]->check($site, (float) $this->config->requestTimeoutSeconds);
        } catch (CheckConfigurationException $checkConfigurationException) {
            $result = CheckResult::error($checkConfigurationException->getMessage());
        }

        $passed   = $result->outcome === CheckOutcome::Pass;
        $newState = $passed ? $this->stateMachine->apply($site->state, CheckOutcome::Pass, $checkedAt) : $site->state;

        $this->historyRepository->append($site->id, new HistoryRecord(
            ts: $checkedAt,
            siteId: $site->id,
            checkType: $site->checkType,
            source: 'self',
            outcome: $result->outcome,
            httpStatus: $result->httpStatus,
            responseTimeMs: $result->responseTimeMs,
            keywordMatched: $result->keywordMatched,
            error: $result->error,
            stateBefore: $site->state->status,
            stateAfter: $newState->status,
            excludedByMaintenance: $site->isUnderMaintenanceAt($checkedAt, $this->maintenanceScheduleRepository->all()),
            role: HistoryRecordRole::Recheck,
        ));

        if ($passed) {
            $this->siteRepository->save($site->withState($newState));
        }

        return $passed;
    }
}

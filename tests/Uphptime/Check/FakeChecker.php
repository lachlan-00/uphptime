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

namespace Uphptime\Tests\Check;

use Uphptime\Check\CheckConfigurationException;
use Uphptime\Check\CheckerInterface;
use Uphptime\Check\CheckResult;
use Uphptime\Site\Site;

/**
 * A scripted CheckerInterface double: returns queued results in order, or throws when configured to.
 */
final class FakeChecker implements CheckerInterface
{
    /** @var CheckResult[] */
    private array $queue;

    private bool $throwsConfigurationError;

    /** @param CheckResult[] $queue */
    public function __construct(array $queue = [], bool $throwsConfigurationError = false)
    {
        $this->queue                    = $queue;
        $this->throwsConfigurationError = $throwsConfigurationError;
    }

    public function check(Site $site, float $timeoutSeconds): CheckResult
    {
        if ($this->throwsConfigurationError) {
            throw new CheckConfigurationException('fake configuration error');
        }

        return array_shift($this->queue) ?? CheckResult::pass();
    }
}

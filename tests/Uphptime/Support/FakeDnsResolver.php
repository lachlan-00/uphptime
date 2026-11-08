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

namespace Uphptime\Tests\Support;

use Uphptime\Pinger\DnsResolverInterface;

/**
 * A scripted DnsResolverInterface double that also counts calls per hostname, so a test can prove a
 * hostname is resolved exactly once rather than re-resolved at connect time.
 */
final class FakeDnsResolver implements DnsResolverInterface
{
    /** @var array<string, int> */
    private array $callCounts = [];

    /** @param array<string, string[]> $responses */
    public function __construct(private readonly array $responses) {}

    public function callCountFor(string $host): int
    {
        return $this->callCounts[$host] ?? 0;
    }

    public function resolve(string $host): array
    {
        $this->callCounts[$host] = ($this->callCounts[$host] ?? 0) + 1;

        return $this->responses[$host] ?? [];
    }
}

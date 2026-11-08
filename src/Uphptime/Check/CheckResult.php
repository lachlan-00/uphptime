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

/**
 * The outcome of one checker run, before the state machine or history layer sees it.
 */
final class CheckResult
{
    public function __construct(
        public readonly CheckOutcome $outcome,
        public readonly ?int $httpStatus,
        public readonly ?int $responseTimeMs,
        public readonly ?bool $keywordMatched,
        public readonly ?string $error,
    ) {}

    public static function error(string $error): self
    {
        return new self(CheckOutcome::Error, null, null, null, $error);
    }

    public static function fail(string $error, ?int $httpStatus = null, ?int $responseTimeMs = null, ?bool $keywordMatched = null): self
    {
        return new self(CheckOutcome::Fail, $httpStatus, $responseTimeMs, $keywordMatched, $error);
    }

    public static function pass(?int $httpStatus = null, ?int $responseTimeMs = null, ?bool $keywordMatched = null): self
    {
        return new self(CheckOutcome::Pass, $httpStatus, $responseTimeMs, $keywordMatched, null);
    }

    public function asUnverifiedError(): self
    {
        return self::error('unverified_network_failure: ' . ($this->error ?? 'unknown error'));
    }

    public function isUnverifiedNetworkFailure(): bool
    {
        return $this->outcome === CheckOutcome::Fail && $this->httpStatus === null;
    }
}

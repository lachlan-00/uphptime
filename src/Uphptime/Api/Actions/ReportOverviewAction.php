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

namespace Uphptime\Api\Actions;

use DateTimeInterface;
use Psr\Http\Message\ResponseFactoryInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Uphptime\Api\ActionInterface;
use Uphptime\Reporting\SiteReportSetBuilder;

/**
 * Every site's uptime % for one period, plus the merged overall figure - pure reuse of
 * SiteReportSetBuilder, the same reporting math `report --group=` prints from the CLI. Gated by
 * ReportApiKeyMiddleware, which never calls this action at all when the report API is unconfigured.
 */
final class ReportOverviewAction implements ActionInterface
{
    public function __construct(
        private readonly SiteReportSetBuilder $builder,
        private readonly ResponseFactoryInterface $responseFactory,
    ) {}

    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        $query  = $request->getQueryParams();
        $period = is_string($query['period'] ?? null) ? $query['period'] : null;
        $date   = is_string($query['date'] ?? null) ? $query['date'] : null;
        $month  = is_string($query['month'] ?? null) ? $query['month'] : null;
        $group  = isset($query['group']) && is_string($query['group']) && $query['group'] !== '' ? $query['group'] : null;

        $result = $this->builder->build($period, $date, $month, $group);
        if ($result === null) {
            return $this->jsonResponse(422, ['error' => 'invalid_period']);
        }

        return $this->jsonResponse(200, [
            'period' => $result->period,
            'generated_at' => $result->generatedAt->format(DateTimeInterface::ATOM),
            'items' => $result->items,
            'overall' => $result->overall->toArray(),
        ]);
    }

    /** @param array<string, mixed> $body */
    private function jsonResponse(int $status, array $body): ResponseInterface
    {
        $response = $this->responseFactory->createResponse($status);
        $response->getBody()->write((string) json_encode($body, JSON_THROW_ON_ERROR));

        return $response->withHeader('Content-Type', 'application/json');
    }
}

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

namespace Uphptime\Api\OpenApi;

use Uphptime\Api\Signing\SignatureHeaders;
use Uphptime\Version;

/**
 * The single source of truth for docs/openapi.json, hand-authored rather than docblock-generated:
 * uphptime's whole REST surface is two routes, which doesn't justify a parsing layer.
 */
final class OpenApiSpecBuilder
{
    /** @return array<string, mixed> */
    public function build(): array
    {
        return [
            'openapi' => '3.0.3',
            'info' => [
                'title' => 'uPHPtime Peer API',
                'version' => Version::VERSION,
                'description' => 'The REST surface one uPHPtime node exposes: to its trusted peers (delegated '
                    . 'checks, HMAC-signed), to the public internet (the result-attestation challenge '
                    . 'token, deliberately unauthenticated), and to its own dashboard client (the report '
                    . 'API, a single bearer key). Generated from '
                    . 'Uphptime\\Api\\OpenApi\\OpenApiSpecBuilder - run "composer api:docs" after changing '
                    . 'any action, the dispatcher, or public/index.php\'s routes.',
            ],
            'servers' => [
                [
                    'url' => '{uphptimeUrl}',
                    'variables' => [
                        'uphptimeUrl' => [
                            'default' => 'https://uphptime.org',
                            'description' => 'Base URL to your uPHPtime Server.'
                        ]
                    ],
                ]
            ],
            'security' => [['HmacSignature' => []]],
            'tags' => [
                ['name' => 'peer', 'description' => 'HMAC-signed, pairwise-trusted peer-to-peer calls.'],
                ['name' => 'challenge', 'description' => 'Unauthenticated result-attestation support.'],
                ['name' => 'report', 'description' => 'Bearer-keyed read access for an owner\'s own dashboard client.'],
            ],
            'paths' => [
                '/peer/v1/check-site' => $this->checkSitePath(),
                '/challenge/v1/{site}' => $this->challengeTokenPath(),
                '/report/v1/overview' => $this->reportOverviewPath(),
            ],
            'components' => [
                'securitySchemes' => $this->securitySchemes(),
                'schemas' => $this->schemas(),
            ],
        ];
    }

    /** @return array<string, mixed> */
    private function challengeTokenPath(): array
    {
        return [
            'get' => [
                'operationId' => 'getChallengeToken',
                'tags' => ['challenge'],
                'summary' => 'Fetch the current result-attestation challenge token for one site.',
                'description' => 'Deliberately unauthenticated: the value returned is about to be made public '
                    . 'anyway, relayed onto the monitored site itself (as a response header or at '
                    . '/.well-known/uphptime) by the admin\'s own web server config. 404s identically for an '
                    . 'unknown site id and for a known site with no challenge delivery configured, so the '
                    . 'endpoint never confirms which site ids exist.',
                'security' => [],
                'parameters' => [
                    [
                        'name' => 'site',
                        'in' => 'path',
                        'required' => true,
                        'schema' => ['type' => 'string'],
                        'description' => 'The site id as configured on this node.',
                    ],
                ],
                'responses' => [
                    '200' => [
                        'description' => 'The currently-live token (issued lazily if none is live yet).',
                        'content' => [
                            'application/json' => ['schema' => ['$ref' => '#/components/schemas/ChallengeTokenResponse']],
                        ],
                    ],
                    '404' => [
                        'description' => 'Unknown site id, or a known site with no challenge delivery configured.',
                        'content' => ['application/json' => ['schema' => ['$ref' => '#/components/schemas/ErrorResponse']]],
                    ],
                ],
            ],
        ];
    }

    /** @return array<string, mixed> */
    private function checkSitePath(): array
    {
        $signatureHeaders = $this->signatureResponseHeaders();

        return [
            'post' => [
                'operationId' => 'checkSite',
                'tags' => ['peer'],
                'summary' => 'Run one delegated check for an ephemeral site description and return a signed result.',
                'description' => 'No site id or owner identity is ever read in the request - the pinger stays '
                    . 'stateless. The response\'s own "attestation" field is never trusted as sent; the owner '
                    . 'always recomputes it from "evidence" against its own current challenge token. '
                    . 'Header examples are illustrative only; use your own key ID and generate fresh values '
                    . 'and a signature for the exact body sent.',
                'parameters' => [
                    [
                        'name' => SignatureHeaders::KEY_ID,
                        'in' => 'header',
                        'required' => true,
                        'schema' => ['type' => 'string'],
                        'example' => '0123456789abcdef',
                        'description' => 'ID of an enabled inbound peer key on the receiving node.',
                    ],
                    [
                        'name' => SignatureHeaders::TIMESTAMP,
                        'in' => 'header',
                        'required' => true,
                        'schema' => ['type' => 'string'],
                        'example' => '1700000000',
                        'description' => 'Current Unix timestamp in seconds; must be within the node\'s clock-skew window.',
                    ],
                    [
                        'name' => SignatureHeaders::NONCE,
                        'in' => 'header',
                        'required' => true,
                        'schema' => ['type' => 'string'],
                        'example' => '0123456789abcdef0123456789abcdef',
                        'description' => 'A fresh, unique nonce for this key and request; replayed nonces are rejected.',
                    ],
                    [
                        'name' => SignatureHeaders::REQUEST_ID,
                        'in' => 'header',
                        'required' => true,
                        'schema' => ['type' => 'string'],
                        'example' => '123e4567-e89b-42d3-a456-426614174000',
                        'description' => 'Request identifier, echoed in signed responses. Generate a fresh identifier for each request.',
                    ],
                ],
                'requestBody' => [
                    'required' => true,
                    'content' => [
                        'application/json' => [
                            'schema' => ['$ref' => '#/components/schemas/CheckSiteRequest'],
                            'examples' => [
                                'http' => [
                                    'summary' => 'HTTP status check',
                                    'value' => ['check_type' => 'http', 'url' => 'https://example.com/', 'timeout_seconds' => 5],
                                ],
                                'http_keyword' => [
                                    'summary' => 'HTTP keyword check',
                                    'value' => [
                                        'check_type' => 'http_keyword',
                                        'url' => 'https://example.com/',
                                        'keyword' => 'Example Domain',
                                        'keyword_mode' => 'contains',
                                        'timeout_seconds' => 5,
                                    ],
                                ],
                                'tcp' => [
                                    'summary' => 'TCP port check',
                                    'value' => ['check_type' => 'tcp', 'host' => 'example.com', 'port' => 443, 'timeout_seconds' => 5],
                                ],
                                'icmp' => [
                                    'summary' => 'ICMP ping check',
                                    'value' => ['check_type' => 'icmp', 'host' => 'example.com', 'timeout_seconds' => 5],
                                ],
                            ],
                        ],
                    ],
                ],
                'responses' => [
                    '200' => [
                        'description' => 'The check ran (possibly outcome "error", meaning the pinger\'s own '
                            . 'machinery failed, never the target).',
                        'headers' => $signatureHeaders,
                        'content' => ['application/json' => ['schema' => ['$ref' => '#/components/schemas/CheckSiteResponse']]],
                    ],
                    '401' => [
                        'description' => 'Authentication failed. Uniform for every reason (bad key, signature, '
                            . 'clock skew, replayed nonce) so a caller can never learn which check failed; '
                            . 'never signed, since the request was never authenticated.',
                        'content' => ['application/json' => ['schema' => ['$ref' => '#/components/schemas/ErrorResponse']]],
                    ],
                    '422' => [
                        'description' => 'Request body was not valid JSON ("invalid_json"), or was valid JSON '
                            . 'missing a field the declared check_type requires ("invalid_payload").',
                        'headers' => $signatureHeaders,
                        'content' => ['application/json' => ['schema' => ['$ref' => '#/components/schemas/ErrorResponse']]],
                    ],
                    '429' => [
                        'description' => 'This peer key is over its configured per-minute rate limit; never '
                            . 'signed, since the request is rejected before the handler runs.',
                        'headers' => [
                            'Retry-After' => ['schema' => ['type' => 'integer'], 'description' => 'Seconds to wait before retrying.'],
                        ],
                        'content' => ['application/json' => ['schema' => ['$ref' => '#/components/schemas/ErrorResponse']]],
                    ],
                    '500' => [
                        'description' => 'The authenticated peer attribute was unexpectedly missing - an '
                            . 'internal wiring fault, not a caller error.',
                        'headers' => $signatureHeaders,
                        'content' => ['application/json' => ['schema' => ['$ref' => '#/components/schemas/ErrorResponse']]],
                    ],
                ],
            ],
        ];
    }

    /** @return array<string, mixed> */
    private function reportOverviewPath(): array
    {
        return [
            'get' => [
                'operationId' => 'getReportOverview',
                'tags' => ['report'],
                'summary' => 'Every site\'s uptime % for one period, plus the merged overall figure.',
                'description' => 'For an owner\'s own dashboard, not a peer - bearer-keyed, not HMAC. Reuses '
                    . 'the exact same reporting math as "bin/uphptime report" and the daily rollups; an '
                    . 'empty "report_api_key" disables the route entirely (404) rather than leaving it '
                    . 'reachable with no real gate.',
                'security' => [['ReportBearerAuth' => []]],
                'parameters' => [
                    [
                        'name' => 'period',
                        'in' => 'query',
                        'required' => false,
                        'schema' => ['type' => 'string', 'enum' => ['day', 'month', 'overall'], 'default' => 'overall'],
                    ],
                    [
                        'name' => 'date',
                        'in' => 'query',
                        'required' => false,
                        'schema' => ['type' => 'string', 'format' => 'date'],
                        'description' => 'Used only when period=day; defaults to today.',
                    ],
                    [
                        'name' => 'month',
                        'in' => 'query',
                        'required' => false,
                        'schema' => ['type' => 'string'],
                        'description' => 'YYYY-MM, used only when period=month; defaults to the current month.',
                    ],
                    [
                        'name' => 'group',
                        'in' => 'query',
                        'required' => false,
                        'schema' => ['type' => 'string'],
                        'description' => 'Exact match against a site\'s configured groups; omitted = every site.',
                    ],
                ],
                'responses' => [
                    '200' => [
                        'description' => 'The report for the requested period.',
                        'content' => ['application/json' => ['schema' => ['$ref' => '#/components/schemas/ReportOverviewResponse']]],
                    ],
                    '401' => [
                        'description' => 'Missing or wrong bearer key.',
                        'content' => ['application/json' => ['schema' => ['$ref' => '#/components/schemas/ErrorResponse']]],
                    ],
                    '404' => [
                        'description' => 'The report API is not configured on this node (empty report_api_key).',
                        'content' => ['application/json' => ['schema' => ['$ref' => '#/components/schemas/ErrorResponse']]],
                    ],
                    '422' => [
                        'description' => 'Unrecognized "period" value.',
                        'content' => ['application/json' => ['schema' => ['$ref' => '#/components/schemas/ErrorResponse']]],
                    ],
                ],
            ],
        ];
    }

    /** @return array<string, mixed> */
    private function schemas(): array
    {
        return [
            'CheckSiteRequest' => [
                'type' => 'object',
                'required' => ['check_type', 'timeout_seconds'],
                'properties' => [
                    'check_type' => ['type' => 'string', 'enum' => ['http', 'http_keyword', 'tcp', 'icmp']],
                    'url' => ['type' => 'string', 'nullable' => true, 'description' => 'Required for http/http_keyword.'],
                    'host' => ['type' => 'string', 'nullable' => true, 'description' => 'Required for tcp/icmp.'],
                    'port' => ['type' => 'integer', 'nullable' => true, 'description' => 'Required for tcp.'],
                    'keyword' => ['type' => 'string', 'nullable' => true, 'description' => 'Required for http_keyword.'],
                    'keyword_mode' => ['type' => 'string', 'enum' => ['contains', 'not_contains'], 'nullable' => true],
                    'timeout_seconds' => ['type' => 'number'],
                    'challenge_delivery' => ['type' => 'string', 'enum' => ['header', 'well_known'], 'nullable' => true],
                ],
            ],
            'CheckSiteResponse' => [
                'type' => 'object',
                'required' => ['outcome', 'attestation', 'served_from_cache', 'cache_age_seconds'],
                'properties' => [
                    'outcome' => ['type' => 'string', 'enum' => ['error', 'fail', 'pass']],
                    'http_status' => ['type' => 'integer', 'nullable' => true],
                    'response_time_ms' => ['type' => 'integer', 'nullable' => true],
                    'keyword_matched' => ['type' => 'boolean', 'nullable' => true],
                    'error' => ['type' => 'string', 'nullable' => true],
                    'evidence' => ['nullable' => true, 'allOf' => [['$ref' => '#/components/schemas/Evidence']]],
                    'attestation' => [
                        'type' => 'string',
                        'description' => 'Always "none" as sent by the pinger; the owner recomputes the real '
                            . 'value ("none"/"evidence"/"token") from "evidence" and never trusts this field.',
                    ],
                    'served_from_cache' => ['type' => 'boolean'],
                    'cache_age_seconds' => ['type' => 'integer'],
                ],
            ],
            'Evidence' => [
                'type' => 'object',
                'description' => 'Result attestation Layer 2. One shared shape across HTTP/TCP/ICMP checks; '
                    . 'TCP and ICMP leave every HTTP-only field null.',
                'required' => ['resolved_ip', 'timing_ms', 'probe_started_at', 'probe_ended_at'],
                'properties' => [
                    'resolved_ip' => ['type' => 'string'],
                    'tls_fingerprint_sha256' => ['type' => 'string', 'nullable' => true],
                    'tls_expiry' => ['type' => 'string', 'format' => 'date-time', 'nullable' => true],
                    'response_date_header' => ['type' => 'string', 'nullable' => true],
                    'headers_hash' => ['type' => 'string', 'nullable' => true],
                    'body_hash' => ['type' => 'string', 'nullable' => true],
                    'timing_ms' => [
                        'type' => 'object',
                        'required' => ['dns', 'connect', 'tls', 'first_byte', 'total'],
                        'properties' => [
                            'dns' => ['type' => 'integer', 'nullable' => true],
                            'connect' => ['type' => 'integer', 'nullable' => true],
                            'tls' => ['type' => 'integer', 'nullable' => true],
                            'first_byte' => ['type' => 'integer', 'nullable' => true],
                            'total' => ['type' => 'integer', 'nullable' => true],
                        ],
                    ],
                    'probe_started_at' => ['type' => 'string', 'format' => 'date-time'],
                    'probe_ended_at' => ['type' => 'string', 'format' => 'date-time'],
                    'challenge_token_observed' => ['type' => 'string', 'nullable' => true],
                ],
            ],
            'ChallengeTokenResponse' => [
                'type' => 'object',
                'required' => ['token', 'expires_at'],
                'properties' => [
                    'token' => ['type' => 'string'],
                    'expires_at' => ['type' => 'string', 'format' => 'date-time'],
                ],
            ],
            'ReportOverviewResponse' => [
                'type' => 'object',
                'required' => ['period', 'generated_at', 'items', 'overall'],
                'properties' => [
                    'period' => ['type' => 'string', 'enum' => ['day', 'month', 'overall']],
                    'generated_at' => ['type' => 'string', 'format' => 'date-time'],
                    'items' => ['type' => 'array', 'items' => ['$ref' => '#/components/schemas/ReportItem']],
                    'overall' => ['$ref' => '#/components/schemas/UptimeFigures'],
                ],
            ],
            'ReportItem' => [
                'type' => 'object',
                'description' => 'One site\'s identity plus its uptime figures for the requested period.',
                'required' => [
                    'id', 'name', 'groups', 'state', 'enabled',
                    'checks_total', 'checks_pass', 'checks_unknown', 'checks_excluded_maintenance',
                ],
                'properties' => [
                    'id' => ['type' => 'string'],
                    'name' => ['type' => 'string'],
                    'groups' => ['type' => 'array', 'items' => ['type' => 'string']],
                    'state' => ['type' => 'string', 'enum' => ['up', 'down', 'suspect', 'unknown']],
                    'enabled' => ['type' => 'boolean'],
                    'checks_total' => ['type' => 'integer'],
                    'checks_pass' => ['type' => 'integer'],
                    'checks_unknown' => ['type' => 'integer', 'deprecated' => true, 'description' => 'Alias of checks_error.'],
                    'checks_error' => ['type' => 'integer', 'description' => 'Checks that ended in an error outcome. These count against uptime_percent.'],
                    'checks_excluded_maintenance' => ['type' => 'integer'],
                    'uptime_percent' => ['type' => 'number', 'nullable' => true, 'description' => 'checks_pass divided by all checks not excluded by maintenance; error outcomes count as not passing.'],
                    'unknown_percent' => ['type' => 'number', 'nullable' => true, 'deprecated' => true, 'description' => 'Alias of error_percent.'],
                    'error_percent' => ['type' => 'number', 'nullable' => true],
                    'self_sourced_percent' => ['type' => 'number', 'nullable' => true],
                ],
            ],
            'UptimeFigures' => [
                'type' => 'object',
                'description' => 'Every per-site field above except identity/state, merged across every item.',
                'required' => ['checks_total', 'checks_pass', 'checks_unknown', 'checks_excluded_maintenance'],
                'properties' => [
                    'checks_total' => ['type' => 'integer'],
                    'checks_pass' => ['type' => 'integer'],
                    'checks_unknown' => ['type' => 'integer', 'deprecated' => true, 'description' => 'Alias of checks_error.'],
                    'checks_error' => ['type' => 'integer', 'description' => 'Checks that ended in an error outcome. These count against uptime_percent.'],
                    'checks_excluded_maintenance' => ['type' => 'integer'],
                    'uptime_percent' => ['type' => 'number', 'nullable' => true, 'description' => 'checks_pass divided by all checks not excluded by maintenance; error outcomes count as not passing.'],
                    'unknown_percent' => ['type' => 'number', 'nullable' => true, 'deprecated' => true, 'description' => 'Alias of error_percent.'],
                    'error_percent' => ['type' => 'number', 'nullable' => true],
                    'self_sourced_percent' => ['type' => 'number', 'nullable' => true],
                ],
            ],
            'ErrorResponse' => [
                'type' => 'object',
                'required' => ['error'],
                'properties' => [
                    'error' => ['type' => 'string'],
                ],
            ],
        ];
    }

    /** @return array<string, mixed> */
    private function securitySchemes(): array
    {
        return [
            'HmacSignature' => [
                'type' => 'apiKey',
                'in' => 'header',
                'name' => SignatureHeaders::SIGNATURE,
                'description' => 'Enter the lowercase hex HMAC-SHA256 signature, not the peer secret. Create '
                    . 'an inbound key on the receiving node with '
                    . '`php bin/uphptime peers:add --id=api-test --label="API test" --key-direction=inbound`. '
                    . 'The command returns `key_id` and a base64-encoded `secret_to_relay`; decode the secret '
                    . 'to raw bytes before using it as the HMAC key. Never send the secret in a header. '
                    . 'Construct the canonical string by joining these seven values with newline characters, '
                    . 'without a trailing newline: uppercase HTTP method; request path (no query string); '
                    . 'key ID; Unix timestamp; nonce; request ID; lowercase hex SHA-256 hash of the exact raw '
                    . 'request body. Sign that string with HMAC-SHA256 and encode the result as lowercase hex. '
                    . 'Supply the four other required headers in the operation parameters. Generate a fresh '
                    . 'timestamp, nonce and signature for each request, including requests from Swagger UI; '
                    . 'the signature must match the exact body sent. Swagger UI does not sign requests automatically. '
                    . 'Authenticated responses have a separate signature over the request ID, response timestamp, '
                    . 'HTTP status and lowercase hex SHA-256 body hash, newline-joined in that order.',
            ],
            'ReportBearerAuth' => [
                'type' => 'http',
                'scheme' => 'bearer',
                'description' => 'A single shared secret from this node\'s "report_api_key" config - an '
                    . 'owner/dashboard credential, not a pairwise peer key. Compared with hash_equals().',
            ],
        ];
    }

    /** @return array<string, mixed> */
    private function signatureResponseHeaders(): array
    {
        return [
            'X-Uphptime-Request-Id' => ['schema' => ['type' => 'string'], 'description' => 'Echoes the request\'s own X-Uphptime-Request-Id.'],
            'X-Uphptime-Response-Timestamp' => ['schema' => ['type' => 'string'], 'description' => 'Unix timestamp the response was signed at.'],
            'X-Uphptime-Signature' => ['schema' => ['type' => 'string'], 'description' => 'HMAC-SHA256 over the response\'s own canonical string.'],
        ];
    }
}

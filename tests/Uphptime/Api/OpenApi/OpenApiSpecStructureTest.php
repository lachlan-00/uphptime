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

namespace Uphptime\Tests\Api\OpenApi;

use PHPUnit\Framework\TestCase;
use Uphptime\Api\OpenApi\OpenApiSpecBuilder;
use Uphptime\Api\Signing\SignatureHeaders;

/**
 * Catches the two ways this spec can silently rot: an unresolved $ref, and docs/openapi.json drifting
 * from what OpenApiSpecBuilder currently produces (the same check "composer api:docs:check" runs, but
 * exercised here under plain "composer tests" too, not only the dedicated qa step).
 */
final class OpenApiSpecStructureTest extends TestCase
{
    public function testCommittedFileMatchesTheBuilderExactly(): void
    {
        $path = dirname(__DIR__, 4) . '/docs/openapi.json';
        self::assertFileExists($path, 'run "composer api:docs" to generate it');

        $expected = json_encode((new OpenApiSpecBuilder())->build(), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n";
        self::assertSame($expected, file_get_contents($path), 'docs/openapi.json is stale - run "composer api:docs"');
    }

    public function testEveryOperationDeclaresAtLeastOneResponse(): void
    {
        $spec = (new OpenApiSpecBuilder())->build();

        foreach ($spec['paths'] as $path => $methods) {
            foreach ($methods as $method => $operation) {
                self::assertNotEmpty($operation['responses'] ?? [], sprintf('%s %s has no documented responses.', $method, $path));
                foreach ($operation['responses'] as $status => $response) {
                    self::assertIsString($response['description'] ?? null, sprintf('%s %s -> %s has no description.', $method, $path, $status));
                }
            }
        }
    }

    public function testEveryRefResolvesToAnExistingSchema(): void
    {
        $spec = (new OpenApiSpecBuilder())->build();

        foreach ($this->collectRefs($spec) as $ref) {
            self::assertTrue(str_starts_with($ref, '#/components/schemas/'), sprintf('Unsupported $ref "%s".', $ref));
            $name = substr($ref, strlen('#/components/schemas/'));
            self::assertArrayHasKey($name, $spec['components']['schemas'], sprintf('$ref "%s" does not resolve.', $ref));
        }
    }

    public function testPeerOperationDeclaresAllRequiredAuthenticationHeaders(): void
    {
        $spec       = (new OpenApiSpecBuilder())->build();
        $operation  = $spec['paths']['/peer/v1/check-site']['post'];
        $parameters = [];
        foreach ($operation['parameters'] as $parameter) {
            self::assertSame('header', $parameter['in']);
            self::assertTrue($parameter['required']);
            self::assertSame('string', $parameter['schema']['type']);
            self::assertNotEmpty($parameter['example']);
            $parameters[] = $parameter['name'];
        }

        self::assertSame([
            SignatureHeaders::KEY_ID,
            SignatureHeaders::TIMESTAMP,
            SignatureHeaders::NONCE,
            SignatureHeaders::REQUEST_ID,
        ], $parameters);
        self::assertSame([['HmacSignature' => []]], $spec['security']);
        self::assertSame(SignatureHeaders::SIGNATURE, $spec['components']['securitySchemes']['HmacSignature']['name']);
        self::assertSame('header', $spec['components']['securitySchemes']['HmacSignature']['in']);
        self::assertSame('apiKey', $spec['components']['securitySchemes']['HmacSignature']['type']);
    }

    public function testPeerOperationHasUsableExamplesForEveryCheckType(): void
    {
        $spec     = (new OpenApiSpecBuilder())->build();
        $examples = $spec['paths']['/peer/v1/check-site']['post']['requestBody']['content']['application/json']['examples'];

        self::assertSame(['http', 'http_keyword', 'tcp', 'icmp'], array_keys($examples));
        foreach ($examples as $type => $example) {
            $payload = $example['value'];
            self::assertSame($type, $payload['check_type']);
            self::assertGreaterThan(0, $payload['timeout_seconds']);
            if ($type === 'http' || $type === 'http_keyword') {
                self::assertNotFalse(filter_var($payload['url'], FILTER_VALIDATE_URL));
            } else {
                self::assertNotEmpty($payload['host']);
            }
            if ($type === 'http_keyword') {
                self::assertNotEmpty($payload['keyword']);
                self::assertContains($payload['keyword_mode'], ['contains', 'not_contains']);
            }
            if ($type === 'tcp') {
                self::assertGreaterThan(0, $payload['port']);
            }
        }
    }

    /**
     * @return string[]
     */
    private function collectRefs(mixed $node): array
    {
        if (!is_array($node)) {
            return [];
        }

        $refs = [];
        foreach ($node as $key => $value) {
            if ($key === '$ref' && is_string($value)) {
                $refs[] = $value;
                continue;
            }
            $refs = [...$refs, ...$this->collectRefs($value)];
        }

        return $refs;
    }
}

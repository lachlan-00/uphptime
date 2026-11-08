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

namespace Uphptime\Tests\Support;

use RuntimeException;

/**
 * A minimal JSON Schema validator scoped to exactly what docs/openapi.json uses (type, nullable, enum,
 * properties/required, items, additionalProperties, $ref, allOf) - not a general-purpose library.
 */
final class OpenApiResponseValidator
{
    /** @param array<string, mixed> $document */
    public function __construct(private readonly array $document) {}

    /**
     * @param array<string, mixed> $responseBody
     * @return string[] validation failures; empty means valid
     */
    public function errorsFor(string $path, string $method, int $status, array $responseBody): array
    {
        $operation = $this->document['paths'][$path][strtolower($method)] ?? null;
        if (!is_array($operation)) {
            return [sprintf('No operation documented for %s %s.', strtoupper($method), $path)];
        }

        $schema = $operation['responses'][(string) $status]['content']['application/json']['schema'] ?? null;
        if (!is_array($schema)) {
            return [sprintf('No %d response schema documented for %s %s.', $status, strtoupper($method), $path)];
        }

        return $this->validate($responseBody, $schema, '$');
    }

    /** @param array<string, mixed> $schema */
    private function resolve(array $schema): array
    {
        if (isset($schema['$ref'])) {
            return $this->resolve($this->resolveRef((string) $schema['$ref']));
        }

        if (isset($schema['allOf']) && is_array($schema['allOf'])) {
            $merged = [];
            foreach ($schema['allOf'] as $sub) {
                $merged = array_merge($merged, is_array($sub) ? $this->resolve($sub) : []);
            }
            $sibling = $schema;
            unset($sibling['allOf']);

            return array_merge($merged, $sibling);
        }

        return $schema;
    }

    /** @return array<string, mixed> */
    private function resolveRef(string $ref): array
    {
        if (!str_starts_with($ref, '#/')) {
            throw new RuntimeException(sprintf('Unsupported $ref "%s".', $ref));
        }

        $node = $this->document;
        foreach (explode('/', substr($ref, 2)) as $segment) {
            if (!is_array($node) || !isset($node[$segment])) {
                throw new RuntimeException(sprintf('$ref "%s" does not resolve.', $ref));
            }
            $node = $node[$segment];
        }

        if (!is_array($node)) {
            throw new RuntimeException(sprintf('$ref "%s" does not resolve to an object.', $ref));
        }

        return $node;
    }

    /**
     * @param array<string, mixed> $rawSchema
     * @return string[]
     */
    private function validate(mixed $value, array $rawSchema, string $at): array
    {
        $schema = $this->resolve($rawSchema);

        if ($value === null) {
            return ($schema['nullable'] ?? false) ? [] : [sprintf('%s: null is not allowed here.', $at)];
        }

        $type       = $schema['type'] ?? null;
        $typeErrors = match ($type) {
            'object' => $this->validateObject($value, $schema, $at),
            'array' => $this->validateArray($value, $schema, $at),
            'string' => is_string($value) ? [] : [sprintf('%s: expected string.', $at)],
            'integer' => is_int($value) ? [] : [sprintf('%s: expected integer.', $at)],
            'number' => is_int($value) || is_float($value) ? [] : [sprintf('%s: expected number.', $at)],
            'boolean' => is_bool($value) ? [] : [sprintf('%s: expected boolean.', $at)],
            default => [],
        };

        if ($typeErrors !== []) {
            return $typeErrors;
        }

        if (isset($schema['enum']) && is_array($schema['enum']) && !in_array($value, $schema['enum'], true)) {
            return [sprintf('%s: "%s" is not one of the allowed enum values.', $at, (string) $value)];
        }

        return [];
    }

    /** @param array<string, mixed> $schema */
    private function validateArray(mixed $value, array $schema, string $at): array
    {
        if (!is_array($value) || !array_is_list($value)) {
            return [sprintf('%s: expected array.', $at)];
        }

        $itemSchema = $schema['items'] ?? null;
        if (!is_array($itemSchema)) {
            return [];
        }

        $errors = [];
        foreach ($value as $index => $item) {
            $errors = [...$errors, ...$this->validate($item, $itemSchema, sprintf('%s[%d]', $at, $index))];
        }

        return $errors;
    }

    /** @param array<string, mixed> $schema */
    private function validateObject(mixed $value, array $schema, string $at): array
    {
        if (!is_array($value) || array_is_list($value)) {
            return [sprintf('%s: expected object.', $at)];
        }

        $errors = [];

        /** @var string[] $required */
        $required = $schema['required'] ?? [];
        foreach ($required as $field) {
            if (!array_key_exists($field, $value)) {
                $errors[] = sprintf('%s: missing required field "%s".', $at, $field);
            }
        }

        /** @var array<string, mixed> $properties */
        $properties = $schema['properties'] ?? [];
        foreach ($value as $field => $fieldValue) {
            if (!isset($properties[$field])) {
                if (($schema['additionalProperties'] ?? true) === false) {
                    $errors[] = sprintf('%s: unexpected field "%s".', $at, $field);
                }
                continue;
            }

            $errors = [...$errors, ...$this->validate($fieldValue, $properties[$field], sprintf('%s.%s', $at, $field))];
        }

        return $errors;
    }
}

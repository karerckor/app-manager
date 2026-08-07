<?php

namespace App\Support\Blueprints;

class JsonSchemaMatcher
{
    /**
     * Match the application metadata against the practical JSON Schema subset
     * used by blueprints: type, required, properties, items, enum, const and
     * the common string/number/array limits. An absent schema is intentionally
     * considered a match so a draft blueprint can be attached immediately.
     */
    public function matches(mixed $value, ?array $schema): bool
    {
        if ($schema === null || $schema === []) {
            return true;
        }

        if (array_key_exists('const', $schema) && $value !== $schema['const']) {
            return false;
        }

        if (isset($schema['enum']) && is_array($schema['enum'])
            && ! in_array($value, $schema['enum'], true)) {
            return false;
        }

        if (isset($schema['allOf']) && is_array($schema['allOf'])) {
            foreach ($schema['allOf'] as $candidate) {
                if (! is_array($candidate) || ! $this->matches($value, $candidate)) {
                    return false;
                }
            }
        }

        if (isset($schema['anyOf']) && is_array($schema['anyOf'])) {
            $matched = collect($schema['anyOf'])
                ->contains(fn ($candidate) => is_array($candidate) && $this->matches($value, $candidate));

            if (! $matched) {
                return false;
            }
        }

        $types = $this->types($schema['type'] ?? null);

        if ($types !== [] && ! collect($types)->contains(fn (string $type) => $this->matchesType($value, $type))) {
            return false;
        }

        if ($this->isObject($value) && ! $this->matchesObject($value, $schema)) {
            return false;
        }

        if ($this->isList($value) && ! $this->matchesList($value, $schema)) {
            return false;
        }

        if (is_string($value)) {
            $length = mb_strlen($value);

            if (isset($schema['minLength']) && $length < (int) $schema['minLength']) {
                return false;
            }

            if (isset($schema['maxLength']) && $length > (int) $schema['maxLength']) {
                return false;
            }

            if (isset($schema['pattern']) && is_string($schema['pattern'])) {
                $pattern = '~'.str_replace('~', '\\~', $schema['pattern']).'~u';

                if (@preg_match($pattern, $value) !== 1) {
                    return false;
                }
            }
        }

        if ((is_int($value) || is_float($value))
            && isset($schema['minimum']) && $value < $schema['minimum']) {
            return false;
        }

        if ((is_int($value) || is_float($value))
            && isset($schema['maximum']) && $value > $schema['maximum']) {
            return false;
        }

        return true;
    }

    /** @return list<string> */
    private function types(mixed $type): array
    {
        if (is_string($type)) {
            return [$type];
        }

        return is_array($type)
            ? array_values(array_filter($type, 'is_string'))
            : [];
    }

    private function matchesType(mixed $value, string $type): bool
    {
        return match ($type) {
            'object' => $this->isObject($value),
            'array' => $this->isList($value),
            'string' => is_string($value),
            'integer' => is_int($value),
            'number' => is_int($value) || is_float($value),
            'boolean' => is_bool($value),
            'null' => $value === null,
            default => false,
        };
    }

    private function matchesObject(array $value, array $schema): bool
    {
        foreach (is_array($schema['required'] ?? null) ? $schema['required'] : [] as $key) {
            if (! is_string($key) || ! array_key_exists($key, $value)) {
                return false;
            }
        }

        foreach (is_array($schema['properties'] ?? null) ? $schema['properties'] : [] as $key => $propertySchema) {
            if (array_key_exists($key, $value)
                && (! is_array($propertySchema) || ! $this->matches($value[$key], $propertySchema))) {
                return false;
            }
        }

        return true;
    }

    private function matchesList(array $value, array $schema): bool
    {
        if (isset($schema['minItems']) && count($value) < (int) $schema['minItems']) {
            return false;
        }

        if (isset($schema['maxItems']) && count($value) > (int) $schema['maxItems']) {
            return false;
        }

        if (isset($schema['items']) && is_array($schema['items'])) {
            foreach ($value as $item) {
                if (! $this->matches($item, $schema['items'])) {
                    return false;
                }
            }
        }

        return true;
    }

    private function isObject(mixed $value): bool
    {
        return is_array($value) && ($value === [] || ! array_is_list($value));
    }

    private function isList(mixed $value): bool
    {
        return is_array($value) && ($value === [] || array_is_list($value));
    }
}

<?php

namespace App\Integrations\CiCd;

use Illuminate\Validation\ValidationException;

class WorkflowInputValidator
{
    /**
     * @param  array<string, array<string, mixed>>  $schema
     * @param  array<string, mixed>  $inputs
     */
    public function validate(array $schema, array $inputs, bool $requireRequired = false): void
    {
        if (count($inputs) > 25 || strlen(json_encode($inputs, JSON_THROW_ON_ERROR)) > 65535) {
            throw ValidationException::withMessages(['inputs' => 'Pipeline inputs exceed the supported limits.']);
        }

        if ($requireRequired) {
            foreach ($schema as $name => $definition) {
                if (($definition['required'] ?? false) && ! array_key_exists($name, $inputs)) {
                    throw ValidationException::withMessages(["inputs.{$name}" => 'This pipeline input is required.']);
                }
            }
        }

        foreach ($inputs as $name => $value) {
            if (! is_string($name) || ! array_key_exists($name, $schema)) {
                throw ValidationException::withMessages(["inputs.{$name}" => 'Input is not declared by this workflow.']);
            }

            if (preg_match('/(?:password|secret|token|private[_-]?key)/i', $name)) {
                throw ValidationException::withMessages(["inputs.{$name}" => 'Secrets must be configured in the CI provider, not App Manager inputs.']);
            }

            $definition = $schema[$name];
            $valid = match ($definition['type'] ?? 'string') {
                'array' => is_array($value),
                'boolean' => is_bool($value),
                'number' => is_int($value) || is_float($value),
                'choice' => in_array($value, $definition['options'] ?? [], true),
                default => is_string($value),
            };

            if ($valid && ($definition['options'] ?? []) !== []) {
                $valid = is_array($value)
                    ? collect($value)->every(fn ($item) => in_array($item, $definition['options'], true))
                    : in_array($value, $definition['options'], true);
            }

            if (! $valid) {
                throw ValidationException::withMessages(["inputs.{$name}" => 'Input value does not match the CI workflow type.']);
            }
        }
    }

    /**
     * @param  array<string, array<string, mixed>>  $schema
     * @param  array<string, mixed>  $configured
     * @param  array<string, mixed>  $provided
     * @return array<string, mixed>
     */
    public function resolve(array $schema, array $configured, array $provided): array
    {
        $inputs = array_replace($configured, $provided);

        foreach ($schema as $name => $definition) {
            if (! array_key_exists($name, $inputs) && array_key_exists('default', $definition) && $definition['default'] !== null) {
                $inputs[$name] = $definition['default'];
            }
        }

        $this->validate($schema, $inputs, true);

        return $inputs;
    }
}

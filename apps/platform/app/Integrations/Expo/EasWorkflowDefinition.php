<?php

namespace App\Integrations\Expo;

use Symfony\Component\Yaml\Exception\ParseException;
use Symfony\Component\Yaml\Yaml;

class EasWorkflowDefinition
{
    /** @return array<string, array<string, mixed>> */
    public function dispatchInputs(string $yaml): array
    {
        try {
            $document = Yaml::parse($yaml);
        } catch (ParseException) {
            return [];
        }

        $inputs = is_array($document)
            ? data_get($document, 'on.workflow_dispatch.inputs', [])
            : [];

        if (! is_array($inputs)) {
            return [];
        }

        return collect($inputs)
            ->filter(fn ($definition, $name) => is_string($name) && is_array($definition))
            ->map(fn (array $definition) => [
                'description' => isset($definition['description'])
                    ? (string) $definition['description']
                    : null,
                'required' => (bool) ($definition['required'] ?? false),
                'default' => $definition['default'] ?? null,
                'type' => $this->type($definition['type'] ?? 'string'),
                'options' => array_values(array_filter(
                    (array) ($definition['options'] ?? []),
                    fn ($option) => is_string($option) || is_int($option) || is_float($option),
                )),
            ])
            ->all();
    }

    private function type(mixed $type): string
    {
        return in_array($type, ['boolean', 'number', 'choice'], true)
            ? $type
            : 'string';
    }
}

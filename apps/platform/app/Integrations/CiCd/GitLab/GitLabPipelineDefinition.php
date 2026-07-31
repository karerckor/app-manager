<?php

namespace App\Integrations\CiCd\GitLab;

use Symfony\Component\Yaml\Exception\ParseException;
use Symfony\Component\Yaml\Yaml;

class GitLabPipelineDefinition
{
    /**
     * @return array<string, array{
     *     description: string|null,
     *     required: bool,
     *     default: mixed,
     *     type: string,
     *     options: list<mixed>,
     *     regex?: string
     * }>
     */
    public function inputs(string $yaml): array
    {
        $documents = preg_split('/^---\s*$/m', $yaml, 2);

        try {
            $definition = Yaml::parse($documents[0] ?? $yaml);
        } catch (ParseException) {
            return [];
        }

        $inputs = is_array($definition)
            && is_array($definition['spec'] ?? null)
            && is_array($definition['spec']['inputs'] ?? null)
                ? $definition['spec']['inputs']
                : [];

        return collect($inputs)
            ->filter(fn ($input, $name) => is_string($name) && is_array($input))
            ->map(function (array $input) {
                $type = in_array(($input['type'] ?? 'string'), ['array', 'boolean', 'number', 'string'], true)
                    ? $input['type'] ?? 'string'
                    : 'string';
                $normalized = [
                    'description' => isset($input['description']) ? (string) $input['description'] : null,
                    'required' => ! array_key_exists('default', $input),
                    'default' => $input['default'] ?? null,
                    'type' => $type,
                    'options' => is_array($input['options'] ?? null) ? array_values($input['options']) : [],
                ];

                if (isset($input['regex']) && is_string($input['regex'])) {
                    $normalized['regex'] = $input['regex'];
                }

                return $normalized;
            })
            ->take(20)
            ->all();
    }
}

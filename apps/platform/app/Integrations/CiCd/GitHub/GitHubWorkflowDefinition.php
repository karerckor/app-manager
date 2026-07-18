<?php

namespace App\Integrations\CiCd\GitHub;

use Symfony\Component\Yaml\Exception\ParseException;
use Symfony\Component\Yaml\Yaml;

class GitHubWorkflowDefinition
{
    /**
     * @return array<string, array{
     *     description: string|null,
     *     required: bool,
     *     default: mixed,
     *     type: string,
     *     options: list<mixed>
     * }>
     */
    public function dispatchInputs(string $yaml): array
    {
        try {
            $definition = Yaml::parse($yaml);
        } catch (ParseException) {
            return [];
        }

        if (! is_array($definition) || ! array_key_exists('on', $definition)) {
            return [];
        }

        $triggers = $definition['on'];

        if ($triggers === 'workflow_dispatch') {
            return [];
        }

        if (is_array($triggers) && array_is_list($triggers)) {
            return [];
        }

        if (! is_array($triggers) || ! array_key_exists('workflow_dispatch', $triggers)) {
            return [];
        }

        $dispatch = $triggers['workflow_dispatch'];
        $inputs = is_array($dispatch) && is_array($dispatch['inputs'] ?? null)
            ? $dispatch['inputs']
            : [];

        return collect($inputs)
            ->filter(fn ($input, $name) => is_string($name) && is_array($input))
            ->map(fn (array $input) => [
                'description' => isset($input['description']) ? (string) $input['description'] : null,
                'required' => (bool) ($input['required'] ?? false),
                'default' => $input['default'] ?? null,
                'type' => in_array(($input['type'] ?? 'string'), ['boolean', 'choice', 'environment', 'number', 'string'], true)
                    ? $input['type'] ?? 'string'
                    : 'string',
                'options' => is_array($input['options'] ?? null) ? array_values($input['options']) : [],
            ])
            ->all();
    }

    public function supportsDispatch(string $yaml): bool
    {
        try {
            $definition = Yaml::parse($yaml);
        } catch (ParseException) {
            return false;
        }

        if (! is_array($definition) || ! array_key_exists('on', $definition)) {
            return false;
        }

        $triggers = $definition['on'];

        return $triggers === 'workflow_dispatch'
            || (is_array($triggers) && array_is_list($triggers) && in_array('workflow_dispatch', $triggers, true))
            || (is_array($triggers) && array_key_exists('workflow_dispatch', $triggers));
    }
}

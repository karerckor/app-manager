<?php

namespace App\Http\Requests;

use App\Enums\BlueprintHookAction;
use App\Enums\BlueprintHookCondition;
use App\Enums\Platform;
use App\Models\Blueprint;
use App\Models\BlueprintHook;
use App\Support\Tags\TagRules;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

abstract class BlueprintHookRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        $required = $this->isUpdate() ? 'sometimes' : 'required';
        $tagRules = TagRules::rules('actions.*.tags');
        $tagRules['actions.*.tags'] = ['required', 'array', 'min:1', 'max:20'];

        return [
            'name' => [$required, 'string', 'max:255'],
            'condition' => [$required, Rule::enum(BlueprintHookCondition::class)],
            'condition_config' => ['sometimes', 'nullable', 'array'],
            'condition_config.file_name' => ['nullable', 'string', 'max:255'],
            'condition_config.platform' => ['nullable', Rule::enum(Platform::class)],
            'actions' => [$required, 'array', 'min:1', 'max:20'],
            'actions.*' => ['required', 'array'],
            'actions.*.type' => ['required', Rule::enum(BlueprintHookAction::class)],
            'enabled' => ['sometimes', 'boolean'],
            ...$tagRules,
        ];
    }

    /** @return list<callable> */
    public function after(): array
    {
        return [function (Validator $validator): void {
            if ($validator->errors()->has('condition')) {
                return;
            }

            /** @var Blueprint $blueprint */
            $blueprint = $this->route('blueprint');
            $hook = $this->route('hook');
            $condition = BlueprintHookCondition::tryFrom(
                $this->input('condition', $hook instanceof BlueprintHook ? $hook->condition->value : ''),
            );
            $submittedConfig = $this->input('condition_config');

            if ($this->has('condition_config') && ! is_array($submittedConfig) && $submittedConfig !== null) {
                return;
            }

            $config = $this->has('condition_config')
                ? ($submittedConfig ?? [])
                : ($hook instanceof BlueprintHook ? ($hook->condition_config ?? []) : []);

            if (in_array($condition, [BlueprintHookCondition::FileMissing, BlueprintHookCondition::FilePresent], true)
                && ! in_array($config['file_name'] ?? null, $blueprint->required_files ?? [], true)) {
                $validator->errors()->add(
                    'condition_config.file_name',
                    'Choose one of the exact file names in this blueprint.',
                );
            }

            if (in_array($condition, [BlueprintHookCondition::PlatformMissing, BlueprintHookCondition::PlatformPresent], true)
                && ! in_array($config['platform'] ?? null, $blueprint->platforms ?? [], true)) {
                $validator->errors()->add(
                    'condition_config.platform',
                    'Choose one of the platforms in this blueprint.',
                );
            }
        }];
    }

    /** @return array<string, mixed> */
    public function blueprintHookData(): array
    {
        $data = $this->validated();
        $hook = $this->route('hook');
        $condition = BlueprintHookCondition::from(
            $data['condition'] ?? ($hook instanceof BlueprintHook ? $hook->condition->value : ''),
        );

        if (array_key_exists('condition', $data) || array_key_exists('condition_config', $data)) {
            $config = array_key_exists('condition_config', $data)
                ? ($data['condition_config'] ?? [])
                : ($hook instanceof BlueprintHook ? ($hook->condition_config ?? []) : []);
            $configKey = $condition->configKey();
            $data['condition_config'] = $configKey ? [$configKey => $config[$configKey]] : null;
        }

        if (array_key_exists('actions', $data)) {
            $data['actions'] = collect($data['actions'])->map(fn (array $action): array => [
                'type' => $action['type'],
                'tags' => TagRules::normalize($action['tags']),
            ])->values()->all();
        }

        if (array_key_exists('enabled', $data)) {
            $data['enabled'] = $this->boolean('enabled');
        } elseif (! $this->isUpdate()) {
            $data['enabled'] = true;
        }

        return $data;
    }

    abstract protected function isUpdate(): bool;
}

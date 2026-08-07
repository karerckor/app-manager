<?php

namespace App\Http\Requests;

use App\Enums\Platform;
use App\Models\Blueprint;
use App\Models\Team;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

abstract class BlueprintRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        /** @var Team $team */
        $team = $this->route('team');
        $blueprint = $this->route('blueprint');
        $required = $this->isUpdate() ? 'sometimes' : 'required';

        return [
            'name' => [$required, 'string', 'max:255', Rule::unique('blueprints')->where('team_id', $team->id)->ignore($blueprint instanceof Blueprint ? $blueprint->id : null)],
            'description' => ['sometimes', 'nullable', 'string', 'max:2000'],
            'json_schema' => ['sometimes', 'nullable', 'array'],
            'required_files' => ['sometimes', 'nullable', 'array', 'max:100'],
            'required_files.*' => ['required', 'string', 'max:255', 'distinct:strict', 'not_regex:/[\\\\\/]/'],
            'platforms' => ['sometimes', 'nullable', 'array'],
            'platforms.*' => ['required', Rule::enum(Platform::class), 'distinct:strict'],
            'requires_expo' => ['sometimes', 'nullable', 'boolean'],
        ];
    }

    /** @return array<string, mixed> */
    public function blueprintData(): array
    {
        $data = $this->validated();

        if (array_key_exists('description', $data)) {
            $data['description'] = filled($data['description']) ? trim($data['description']) : null;
        }

        if (array_key_exists('required_files', $data)) {
            $data['required_files'] = collect($data['required_files'] ?? [])
                ->map(fn (string $name): string => trim($name))
                ->filter()
                ->values()
                ->all();
        }

        if (array_key_exists('platforms', $data)) {
            $data['platforms'] = array_values($data['platforms'] ?? []);
        }

        return $data;
    }

    abstract protected function isUpdate(): bool;
}

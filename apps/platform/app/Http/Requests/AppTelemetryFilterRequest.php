<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class AppTelemetryFilterRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        $app = $this->route('mobileApp');

        return [
            'view' => ['nullable', Rule::in(['all', 'issues', 'errors', 'traces'])],
            'environment' => ['nullable', 'string', 'max:255'],
            'identifiers' => ['nullable', 'array', 'max:100'],
            'identifiers.*' => ['uuid', Rule::exists('app_identifiers', 'id')->where('mobile_app_id', $app->id)],
            'search' => ['nullable', 'string', 'max:255'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
        ];
    }

    /** @return array{view: string, environment: string, identifiers: list<string>, search: string, per_page: int} */
    public function telemetryFilters(): array
    {
        $data = $this->validated();

        return [
            'view' => in_array($data['view'] ?? 'issues', ['all', 'errors'], true) ? 'issues' : ($data['view'] ?? 'issues'),
            'environment' => trim((string) ($data['environment'] ?? '')),
            'identifiers' => collect($data['identifiers'] ?? [])->filter()->unique()->values()->all(),
            'search' => trim((string) ($data['search'] ?? '')),
            'per_page' => (int) ($data['per_page'] ?? 25),
        ];
    }
}

<?php

namespace App\Http\Requests;

use App\Enums\SentryEventSource;
use App\Enums\SentryRoutingMethod;
use App\Enums\SentryTargetMode;
use App\Integrations\Sentry\SentryDsnUrl;
use App\Models\AppIdentifier;
use App\Models\SentryDsn;
use App\Models\Team;
use App\Rules\ValidSentryDsn;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class SentryDsnRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        $this->merge([
            'target_mode' => $this->input('target_mode', SentryTargetMode::Selected->value),
            'identifier_key' => trim((string) $this->input('identifier_key', '')),
            'upstream_dsn' => trim((string) $this->input('upstream_dsn', '')),
        ]);
    }

    public function rules(): array
    {
        /** @var Team $team */
        $team = $this->route('team');
        /** @var ?SentryDsn $dsn */
        $dsn = $this->route('sentryDsn');
        $selectedTargets = $this->input('target_mode') === SentryTargetMode::Selected->value;
        $blueprintTargets = $this->input('target_mode') === SentryTargetMode::Blueprints->value;

        return [
            'name' => ['required', 'string', 'max:255'],
            'routing_method' => ['required', Rule::enum(SentryRoutingMethod::class)],
            'event_source' => ['nullable', Rule::requiredIf($this->input('routing_method') === SentryRoutingMethod::Event->value), Rule::enum(SentryEventSource::class)],
            'app_key' => ['required', 'string', 'max:120', 'regex:/^[A-Za-z][A-Za-z0-9_.-]*$/'],
            'identifier_key' => ['nullable', 'string', 'max:120', 'regex:/^[A-Za-z][A-Za-z0-9_.-]*$/', 'different:app_key'],
            'target_mode' => ['required', Rule::enum(SentryTargetMode::class)],
            'blueprint_ids' => [$blueprintTargets ? 'required' : 'nullable', 'array', 'max:100', ...($blueprintTargets ? ['min:1'] : [])],
            'blueprint_ids.*' => ['uuid', 'distinct', Rule::exists('blueprints', 'id')->where('team_id', $team->id)],
            'routes' => [$selectedTargets ? 'required' : 'nullable', 'array', 'max:500', ...($selectedTargets ? ['min:1'] : [])],
            'routes.*.mobile_app_id' => ['required', 'uuid', Rule::exists('mobile_apps', 'id')->where('team_id', $team->id)],
            'routes.*.app_identifier_id' => ['nullable', 'uuid', Rule::exists('app_identifiers', 'id')],
            'forward_enabled' => ['required', 'boolean'],
            'upstream_dsn' => ['nullable', 'string', 'max:2048', Rule::requiredIf($this->boolean('forward_enabled') && ! filled($dsn?->upstream_dsn)), new ValidSentryDsn(app(SentryDsnUrl::class))],
            'enabled' => ['sometimes', 'boolean'],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator) {
            if ($validator->errors()->isNotEmpty()) {
                return;
            }

            if ($this->input('target_mode') !== SentryTargetMode::Selected->value) {
                return;
            }

            $seen = [];
            $routes = collect($this->input('routes', []));
            foreach ($routes->groupBy('mobile_app_id') as $appRoutes) {
                if ($appRoutes->count() > 1 && $appRoutes->contains(fn (array $route) => blank($route['app_identifier_id'] ?? null))) {
                    $validator->errors()->add('routes', 'An application-wide target cannot be combined with identifier-specific targets for the same application.');
                }
            }

            foreach ($routes as $index => $route) {
                $identifierId = $route['app_identifier_id'] ?? null;
                $key = $route['mobile_app_id'].':'.($identifierId ?: '*');
                if (isset($seen[$key])) {
                    $validator->errors()->add("routes.{$index}.mobile_app_id", 'Each application target may only be added once.');
                }
                $seen[$key] = true;

                if ($identifierId && ! AppIdentifier::query()->whereKey($identifierId)->where('mobile_app_id', $route['mobile_app_id'])->exists()) {
                    $validator->errors()->add("routes.{$index}.app_identifier_id", 'The identifier does not belong to the selected application.');
                }
            }
        });
    }

    /** @return array<string, mixed> */
    public function sentryData(): array
    {
        return [
            ...$this->validated(),
            'blueprint_ids' => $this->validated('blueprint_ids', []),
            'routes' => $this->validated('routes', []),
            'enabled' => $this->boolean('enabled', true),
        ];
    }
}

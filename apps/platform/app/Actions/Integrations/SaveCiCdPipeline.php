<?php

namespace App\Actions\Integrations;

use App\Enums\BuildReportingMode;
use App\Enums\IntegrationProvider;
use App\Integrations\CiCd\CiCdProviderRegistry;
use App\Integrations\CiCd\Data\PipelineData;
use App\Integrations\CiCd\WorkflowInputValidator;
use App\Models\AppIdentifier;
use App\Models\CiCdPipeline;
use App\Models\Integration;
use App\Models\MobileApp;
use App\Models\Team;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class SaveCiCdPipeline
{
    public function __construct(
        private readonly CiCdProviderRegistry $providers,
        private readonly WorkflowInputValidator $inputValidator,
    ) {}

    /** @param array<string, mixed> $data */
    public function handle(
        Team $team,
        Integration $integration,
        User $user,
        array $data,
        ?CiCdPipeline $pipeline = null,
    ): CiCdPipeline {
        abort_unless(
            $integration->team_id === $team->id
            && $integration->supportsCiCd()
            && $integration->status->canDispatch(),
            404,
        );
        $mobileApp = MobileApp::query()
            ->whereKey($data['mobile_app_id'])
            ->where('team_id', $team->id)
            ->firstOrFail();
        $identifier = isset($data['app_identifier_id'])
            ? AppIdentifier::query()
                ->whereKey($data['app_identifier_id'])
                ->where('mobile_app_id', $mobileApp->id)
                ->firstOrFail()
            : null;
        $provider = $this->providers->for($integration->provider);
        $repository = collect($provider->repositories($integration))
            ->first(fn ($item) => $item->fullName === $data['repository_full_name']);

        if (! $repository) {
            throw ValidationException::withMessages(['repository_full_name' => 'Repository is not accessible to this integration.']);
        }

        if ($integration->provider === IntegrationProvider::Expo
            && ! $mobileApp->expoProject()
                ->where('integration_id', $integration->id)
                ->where('external_id', $repository->id)
                ->where('exists_on_expo', true)
                ->exists()) {
            throw ValidationException::withMessages([
                'mobile_app_id' => 'EAS Workflows can only be bound to the application linked to this Expo project.',
            ]);
        }

        $workflow = collect($provider->pipelines($integration, $repository))
            ->first(fn (PipelineData $item) => $item->id === (string) $data['workflow_id']);

        if (! $workflow) {
            throw ValidationException::withMessages(['workflow_id' => 'Pipeline is unavailable or cannot be dispatched.']);
        }

        $availableRef = collect($provider->refs($integration, $repository))
            ->contains(fn ($item) => $item->name === $data['default_ref']);

        if (! $availableRef) {
            throw ValidationException::withMessages(['default_ref' => 'The selected branch or tag is unavailable.']);
        }

        $inputs = $data['input_defaults'] ?? [];
        $reportingMode = BuildReportingMode::from($data['reporting_mode'] ?? BuildReportingMode::Automatic->value);

        if ($integration->provider === IntegrationProvider::Expo
            && $reportingMode !== BuildReportingMode::Automatic) {
            throw ValidationException::withMessages([
                'reporting_mode' => 'EAS Workflows always report status through Expo.',
            ]);
        }

        if ($reportingMode === BuildReportingMode::Manual) {
            $buildIdInput = $data['build_id_input'] ?? null;
            $definition = is_string($buildIdInput) ? ($workflow->inputs[$buildIdInput] ?? null) : null;

            if (! is_array($definition) || ($definition['type'] ?? 'string') !== 'string') {
                throw ValidationException::withMessages([
                    'build_id_input' => 'Manual reporting requires a string workflow input that receives the App Manager build ID.',
                ]);
            }

            unset($inputs[$buildIdInput]);
        }

        try {
            $this->inputValidator->validate($workflow->inputs, $inputs);
        } catch (ValidationException $exception) {
            throw ValidationException::withMessages(collect($exception->errors())->mapWithKeys(fn ($messages, $key) => [
                'input_defaults'.str($key)->after('inputs') => $messages,
            ])->all());
        }

        return DB::transaction(function () use ($team, $integration, $user, $data, $pipeline, $mobileApp, $identifier, $repository, $workflow, $inputs, $reportingMode) {
            $pipeline ??= new CiCdPipeline([
                'team_id' => $team->id,
                'integration_id' => $integration->id,
                'created_by_user_id' => $user->id,
            ]);
            $pipeline->fill([
                'mobile_app_id' => $mobileApp->id,
                'app_identifier_id' => $identifier?->id,
                'name' => $data['name'],
                'profile_type' => $data['profile_type'],
                'enabled' => $data['enabled'] ?? true,
                'reporting_mode' => $reportingMode,
                'build_id_input' => $reportingMode === BuildReportingMode::Manual
                    ? $data['build_id_input']
                    : null,
                'repository_id' => $repository->id,
                'repository_full_name' => $repository->fullName,
                'workflow_id' => $workflow->id,
                'workflow_name' => $workflow->name,
                'workflow_path' => $workflow->path,
                'default_ref' => $data['default_ref'],
                'input_schema' => $workflow->inputs,
                'input_defaults' => $inputs,
                'updated_by_user_id' => $user->id,
            ]);
            $pipeline->save();

            return $pipeline->refresh();
        });
    }
}

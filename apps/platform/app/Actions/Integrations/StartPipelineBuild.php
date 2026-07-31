<?php

namespace App\Actions\Integrations;

use App\Billing\Contracts\AuthorizesBuildCreation;
use App\Billing\Contracts\RecordsUsage;
use App\Enums\BuildReportingMode;
use App\Enums\BuildStatus;
use App\Enums\CiCdDispatchStatus;
use App\Enums\WebhookEventType;
use App\Integrations\CiCd\CiCdProviderRegistry;
use App\Integrations\CiCd\WorkflowInputValidator;
use App\Integrations\Webhooks\WebhookEventRecorder;
use App\Integrations\Webhooks\WebhookPayload;
use App\Jobs\Integrations\DispatchCiCdRun;
use App\Models\BuildRecord;
use App\Models\CiCdPipeline;
use App\Models\CiCdRun;
use App\Models\Integration;
use App\Models\Team;
use App\Models\User;
use App\Support\Audit\ActorContext;
use App\Support\Audit\ActorContextManager;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class StartPipelineBuild
{
    public function __construct(
        private readonly CiCdProviderRegistry $providers,
        private readonly WorkflowInputValidator $inputValidator,
        private readonly WebhookEventRecorder $webhookEvents,
        private readonly AuthorizesBuildCreation $buildAuthorizer,
        private readonly RecordsUsage $usage,
    ) {}

    /** @param array<string, mixed> $providedInputs */
    public function handle(
        Team $team,
        Integration $integration,
        CiCdPipeline $pipeline,
        User $user,
        string $requestUuid,
        ?string $ref = null,
        array $providedInputs = [],
    ): BuildRecord {
        $existing = BuildRecord::query()
            ->where('team_id', $team->id)
            ->where('request_uuid', $requestUuid)
            ->first();

        if ($existing) {
            return $existing;
        }

        $this->buildAuthorizer->authorize($team);

        abort_unless(
            $pipeline->team_id === $team->id
            && $pipeline->integration_id === $integration->id
            && $pipeline->enabled
            && $integration->status->canDispatch(),
            404,
        );

        $ref ??= $pipeline->default_ref;
        $provider = $this->providers->for($integration->provider);
        $repository = collect($provider->repositories($integration))
            ->first(fn ($item) => $item->id === $pipeline->repository_id
                && $item->fullName === $pipeline->repository_full_name);

        if (! $repository) {
            throw ValidationException::withMessages(['pipeline' => 'The pipeline repository is no longer accessible.']);
        }

        $refExists = collect($provider->refs($integration, $repository))
            ->contains(fn ($item) => $item->name === $ref);

        if (! $refExists) {
            throw ValidationException::withMessages(['ref' => 'The selected branch or tag is unavailable.']);
        }

        $buildId = (string) Str::uuid();

        if ($pipeline->reporting_mode === BuildReportingMode::Manual
            && (! is_string($pipeline->build_id_input) || $pipeline->build_id_input === '')) {
            throw ValidationException::withMessages([
                'pipeline' => 'The manual pipeline has no build ID workflow input.',
            ]);
        }

        $providedInputs = $pipeline->reporting_mode === BuildReportingMode::Manual
            ? array_replace($providedInputs, [$pipeline->build_id_input => $buildId])
            : $providedInputs;
        $inputs = $this->inputValidator->resolve(
            $pipeline->input_schema ?? [],
            $pipeline->input_defaults ?? [],
            $providedInputs,
        );

        return DB::transaction(function () use ($team, $integration, $pipeline, $user, $requestUuid, $ref, $inputs, $buildId) {
            $build = new BuildRecord([
                'team_id' => $team->id,
                'mobile_app_id' => $pipeline->mobile_app_id,
                'app_identifier_id' => $pipeline->app_identifier_id,
                'requested_by_user_id' => $user->id,
                'request_uuid' => $requestUuid,
                'profile_type' => $pipeline->profile_type,
                'status' => BuildStatus::Pending,
                'reporting_mode' => $pipeline->reporting_mode,
                'parameters' => $inputs,
                'context_snapshot' => [
                    'schema_version' => 1,
                    'source' => 'ci_cd',
                    'app' => [
                        'id' => $pipeline->mobileApp->id,
                        'slug' => $pipeline->mobileApp->slug,
                        'name' => $pipeline->mobileApp->name,
                    ],
                    'target' => $pipeline->appIdentifier ? [
                        'id' => $pipeline->appIdentifier->id,
                        'platform' => $pipeline->appIdentifier->platform->value,
                        'identifier' => $pipeline->appIdentifier->identifier,
                        'version' => $pipeline->appIdentifier->version,
                        'version_code' => $pipeline->appIdentifier->version_code,
                    ] : null,
                    'profile_type' => $pipeline->profile_type->value,
                    'pipeline' => [
                        'id' => $pipeline->id,
                        'name' => $pipeline->name,
                    ],
                ],
            ]);
            $build->id = $buildId;
            $build->save();
            $run = CiCdRun::create([
                'team_id' => $team->id,
                'build_record_id' => $build->id,
                'pipeline_id' => $pipeline->id,
                'integration_id' => $integration->id,
                'requested_by_user_id' => $user->id,
                'dispatch_status' => CiCdDispatchStatus::Created,
                'reporting_mode' => $pipeline->reporting_mode,
                'request_snapshot' => [
                    'ref' => $ref,
                    'inputs' => $inputs,
                ],
            ]);

            $this->webhookEvents->record(
                $team,
                WebhookEventType::BuildCreated,
                $build,
                WebhookPayload::build($build),
                ActorContext::forUser($user, app(ActorContextManager::class)->current()->source),
            );

            $this->usage->recordBuildRun($build);

            DB::afterCommit(fn () => DispatchCiCdRun::dispatch($run->id)->onQueue('integrations'));

            return $build->load('ciCdRun');
        });
    }
}

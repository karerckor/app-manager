<?php

namespace App\Actions\Builds;

use App\Enums\BuildReportingMode;
use App\Enums\BuildStatus;
use App\Enums\ProfileType;
use App\Enums\WebhookEventType;
use App\Integrations\Expo\ExpoClient;
use App\Integrations\Expo\ExpoGraphqlException;
use App\Integrations\Webhooks\WebhookEventRecorder;
use App\Integrations\Webhooks\WebhookPayload;
use App\Jobs\Integrations\ReconcileEasBuild;
use App\Models\AppIdentifier;
use App\Models\BuildRecord;
use App\Models\ExpoProject;
use App\Models\MobileApp;
use App\Models\Team;
use App\Models\User;
use App\Support\Audit\ActorContext;
use App\Support\Audit\ActorContextManager;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class CreateEasBuild
{
    public function __construct(
        private readonly ExpoClient $expoClient,
        private readonly WebhookEventRecorder $webhookEvents,
    ) {}

    /** @param array<string, mixed> $context */
    public function handle(
        Team $team,
        MobileApp $mobileApp,
        User $user,
        string $requestUuid,
        string $expoBuildId,
        ProfileType $profileType,
        ?AppIdentifier $appIdentifier = null,
        array $context = [],
        array $parameters = [],
    ): BuildRecord {
        $existing = BuildRecord::query()
            ->where('team_id', $team->id)
            ->where('request_uuid', $requestUuid)
            ->first();

        if ($existing) {
            abort_unless($existing->mobile_app_id === $mobileApp->id, 409, 'The request UUID is already used by another build.');

            return $existing;
        }

        abort_unless(
            $mobileApp->team_id === $team->id
            && ($appIdentifier === null || $appIdentifier->mobile_app_id === $mobileApp->id),
            404,
        );

        $expoProject = ExpoProject::query()
            ->where('mobile_app_id', $mobileApp->id)
            ->where('team_id', $team->id)
            ->with('integration')
            ->first();

        if (! $expoProject) {
            throw ValidationException::withMessages([
                'expo' => 'This application is not connected to EAS. Please link an Expo project first.',
            ]);
        }

        if (! $expoProject->exists_on_expo) {
            throw ValidationException::withMessages([
                'expo' => 'This Expo project appears to be detached. The project no longer exists on expo.dev.',
            ]);
        }

        $integration = $expoProject->integration;

        if (! $integration->status->canDispatch()) {
            throw ValidationException::withMessages([
                'expo' => 'The Expo integration is not active. Please reconnect your Expo account.',
            ]);
        }

        try {
            $projectData = $this->expoClient->project($integration, $expoProject->external_id, buildLimit: 50);
        } catch (ExpoGraphqlException $exception) {
            throw ValidationException::withMessages([
                'expo_build_id' => 'Unable to fetch builds from Expo: '.$exception->getMessage(),
            ]);
        }

        $easBuild = collect((array) ($projectData['builds'] ?? []))
            ->firstWhere('id', $expoBuildId);

        if (! is_array($easBuild) || empty($easBuild['id'])) {
            throw ValidationException::withMessages([
                'expo_build_id' => 'The specified EAS build ID was not found in recent builds. It may be too old or belong to a different project.',
            ]);
        }

        return DB::transaction(function () use (
            $team,
            $mobileApp,
            $user,
            $requestUuid,
            $expoBuildId,
            $profileType,
            $appIdentifier,
            $context,
            $parameters,
            $easBuild,
            $expoProject,
        ) {
            $build = BuildRecord::create([
                'team_id' => $team->id,
                'mobile_app_id' => $mobileApp->id,
                'app_identifier_id' => $appIdentifier?->id,
                'requested_by_user_id' => $user->id,
                'request_uuid' => $requestUuid,
                'profile_type' => $profileType,
                'status' => BuildStatus::Pending,
                'reporting_mode' => BuildReportingMode::Eas,
                'expo_build_id' => $expoBuildId,
                'parameters' => $parameters,
                'context_snapshot' => [
                    'schema_version' => 1,
                    'source' => 'api',
                    'app' => [
                        'id' => $mobileApp->id,
                        'slug' => $mobileApp->slug,
                        'name' => $mobileApp->name,
                    ],
                    'target' => $appIdentifier ? [
                        'id' => $appIdentifier->id,
                        'platform' => $appIdentifier->platform->value,
                        'identifier' => $appIdentifier->identifier,
                    ] : null,
                    'profile_type' => $profileType->value,
                    'context' => $context,
                    'eas' => [
                        'build_id' => $expoBuildId,
                        'project_id' => $expoProject->external_id,
                        'project_slug' => $expoProject->slug,
                        'platform' => $easBuild['platform'] ?? null,
                        'build_profile' => $easBuild['buildProfile'] ?? null,
                    ],
                ],
            ]);

            $this->webhookEvents->record(
                $team,
                WebhookEventType::BuildCreated,
                $build,
                WebhookPayload::build($build),
                ActorContext::forUser($user, app(ActorContextManager::class)->current()->source),
            );

            DB::afterCommit(fn () => ReconcileEasBuild::dispatch($build->id)->onQueue('integrations'));

            return $build->refresh();
        });
    }
}

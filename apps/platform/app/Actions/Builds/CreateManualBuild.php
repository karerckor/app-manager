<?php

namespace App\Actions\Builds;

use App\Enums\BuildReportingMode;
use App\Enums\BuildStatus;
use App\Enums\ProfileType;
use App\Enums\WebhookEventType;
use App\Integrations\Webhooks\WebhookEventRecorder;
use App\Integrations\Webhooks\WebhookPayload;
use App\Models\AppIdentifier;
use App\Models\BuildRecord;
use App\Models\MobileApp;
use App\Models\Team;
use App\Models\User;
use App\Support\Audit\ActorContext;
use App\Support\Audit\ActorContextManager;
use App\Support\Builds\BuildLifecycle;
use Illuminate\Support\Facades\DB;

class CreateManualBuild
{
    public function __construct(
        private readonly WebhookEventRecorder $webhookEvents,
        private readonly BuildLifecycle $lifecycle,
    ) {}

    /** @param array<string, mixed> $context */
    public function handle(
        Team $team,
        MobileApp $mobileApp,
        User $user,
        string $requestUuid,
        ProfileType $profileType,
        ?AppIdentifier $appIdentifier = null,
        array $context = [],
        array $parameters = [],
        string $source = 'api',
        BuildStatus $status = BuildStatus::Pending,
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

        return DB::transaction(function () use (
            $team,
            $mobileApp,
            $user,
            $requestUuid,
            $profileType,
            $appIdentifier,
            $context,
            $parameters,
            $source,
            $status,
        ) {
            $build = BuildRecord::create([
                'team_id' => $team->id,
                'mobile_app_id' => $mobileApp->id,
                'app_identifier_id' => $appIdentifier?->id,
                'requested_by_user_id' => $user->id,
                'request_uuid' => $requestUuid,
                'profile_type' => $profileType,
                'status' => BuildStatus::Pending,
                'reporting_mode' => BuildReportingMode::Manual,
                'parameters' => $parameters,
                'context_snapshot' => [
                    'schema_version' => 1,
                    'source' => $source,
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
                ],
            ]);

            $this->webhookEvents->record(
                $team,
                WebhookEventType::BuildCreated,
                $build,
                WebhookPayload::build($build),
                ActorContext::forUser($user, app(ActorContextManager::class)->current()->source),
            );

            if ($status->isTerminal()) {
                return $this->lifecycle->finish(
                    $build,
                    $status,
                    actor: ActorContext::forUser($user, app(ActorContextManager::class)->current()->source),
                );
            }

            return $build->refresh();
        });
    }
}

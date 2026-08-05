<?php

namespace App\Integrations\Sentry;

use App\Enums\IntegrationKind;
use App\Enums\IntegrationProvider;
use App\Enums\IntegrationStatus;
use App\Enums\SentryRoutingMethod;
use App\Models\Integration;
use App\Models\SentryDsn;
use App\Models\Team;
use App\Models\User;
use Illuminate\Support\Facades\DB;

class SentryDsnManager
{
    /** @param array<string, mixed> $data */
    public function create(Team $team, User $actor, array $data): SentryDsn
    {
        return DB::transaction(function () use ($team, $actor, $data) {
            $integration = Integration::create(['team_id' => $team->id, 'kind' => IntegrationKind::Observability, 'provider' => IntegrationProvider::Sentry, 'name' => $data['name'], 'status' => $data['enabled'] ? IntegrationStatus::Active : IntegrationStatus::Disabled, 'disabled_at' => $data['enabled'] ? null : now(), 'created_by_user_id' => $actor->id, 'updated_by_user_id' => $actor->id]);
            $dsn = SentryDsn::create(['team_id' => $team->id, 'integration_id' => $integration->id, 'public_key' => bin2hex(random_bytes(16)), 'project_id' => $this->newProjectId(), 'routing_method' => $data['routing_method'], 'event_source' => $data['routing_method'] === SentryRoutingMethod::Event->value ? $data['event_source'] : null, 'app_key' => $data['app_key'], 'identifier_key' => $data['identifier_key'] ?: null, 'upstream_dsn' => $data['forward_enabled'] ? $data['upstream_dsn'] : null]);
            $this->syncRoutes($dsn, $data['routes']);
            $integration->update(['external_id' => $dsn->id]);

            return $dsn->load(['integration', 'routes.mobileApp', 'routes.appIdentifier']);
        });
    }

    /** @param array<string, mixed> $data */
    public function update(SentryDsn $dsn, User $actor, array $data): SentryDsn
    {
        DB::transaction(function () use ($dsn, $actor, $data) {
            $updates = ['routing_method' => $data['routing_method'], 'event_source' => $data['routing_method'] === SentryRoutingMethod::Event->value ? $data['event_source'] : null, 'app_key' => $data['app_key'], 'identifier_key' => $data['identifier_key'] ?: null];
            if (! $data['forward_enabled']) {
                $updates['upstream_dsn'] = null;
            } elseif (filled($data['upstream_dsn'])) {
                $updates['upstream_dsn'] = $data['upstream_dsn'];
            }
            $dsn->update($updates);
            $this->syncRoutes($dsn, $data['routes']);
            $dsn->integration->update(['name' => $data['name'], 'status' => $data['enabled'] ? IntegrationStatus::Active : IntegrationStatus::Disabled, 'disabled_at' => $data['enabled'] ? null : now(), 'updated_by_user_id' => $actor->id]);
        });

        return $dsn->fresh()->load(['integration', 'routes.mobileApp', 'routes.appIdentifier']);
    }

    public function delete(SentryDsn $dsn, User $actor): void
    {
        DB::transaction(function () use ($dsn, $actor) {
            $dsn->integration->update(['status' => IntegrationStatus::Disconnected, 'disconnected_at' => now(), 'updated_by_user_id' => $actor->id]);
            $dsn->delete();
        });
    }

    /** @param list<array{mobile_app_id: string, app_identifier_id?: ?string}> $routes */
    private function syncRoutes(SentryDsn $dsn, array $routes): void
    {
        $existing = $dsn->routes()->get()->keyBy(fn ($route) => $route->mobile_app_id.':'.($route->app_identifier_id ?: '*'));
        $kept = [];
        foreach ($routes as $route) {
            $key = $route['mobile_app_id'].':'.(($route['app_identifier_id'] ?? null) ?: '*');
            $configured = $existing->get($key) ?? $dsn->routes()->create(['public_key' => bin2hex(random_bytes(16)), 'mobile_app_id' => $route['mobile_app_id'], 'app_identifier_id' => $route['app_identifier_id'] ?? null]);
            $kept[] = $configured->id;
        }
        $dsn->routes()->whereNotIn('id', $kept)->delete();
    }

    private function newProjectId(): string
    {
        do {
            $projectId = (string) random_int(100000000, 999999999999);
        } while (SentryDsn::withTrashed()->where('project_id', $projectId)->exists());

        return $projectId;
    }
}

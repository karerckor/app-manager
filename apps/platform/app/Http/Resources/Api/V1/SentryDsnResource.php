<?php

namespace App\Http\Resources\Api\V1;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class SentryDsnResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'integration_id' => $this->integration_id,
            'name' => $this->integration->name,
            'enabled' => $this->integration->status->value === 'active',
            'dsn' => $this->dsn(),
            'project_id' => $this->project_id,
            'routing_method' => $this->routing_method->value,
            'event_source' => $this->event_source?->value,
            'app_key' => $this->app_key,
            'identifier_key' => $this->identifier_key,
            'forward_enabled' => filled($this->upstream_dsn),
            'consecutive_forward_failures' => $this->consecutive_forward_failures,
            'last_event_at' => $this->last_event_at,
            'last_forwarded_at' => $this->last_forwarded_at,
            'routes' => $this->routes->map(fn ($route) => [
                'id' => $route->id,
                'dsn' => $this->dsn($route->public_key),
                'mobile_app_id' => $route->mobile_app_id,
                'app_slug' => $route->mobileApp?->slug,
                'app_identifier_id' => $route->app_identifier_id,
                'identifier' => $route->appIdentifier?->identifier,
                'platform' => $route->appIdentifier?->platform?->value,
            ])->values(),
            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,
        ];
    }
}

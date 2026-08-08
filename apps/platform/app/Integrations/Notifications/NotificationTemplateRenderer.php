<?php

namespace App\Integrations\Notifications;

use App\Models\AppIdentifier;
use App\Models\Artifact;
use App\Models\BuildRecord;
use App\Models\Credential;
use App\Models\MobileApp;
use App\Models\NotificationDestination;
use App\Models\WebhookEvent;
use Carbon\CarbonImmutable;
use Throwable;

class NotificationTemplateRenderer
{
    public function render(WebhookEvent $event, NotificationDestination $destination): string
    {
        $eventKey = $event->event_type->value;
        // Plain array access, not data_get(): event keys contain dots ("build.completed"),
        // which data_get() reads as a nested path and never finds, silently falling back
        // to the catalog default and discarding every customised template.
        $template = ($destination->templates ?? [])[$eventKey]
            ?? data_get(collect(NotificationEventCatalog::all())->firstWhere('key', $eventKey), 'default_template')
            ?? $eventKey;
        $values = $this->values($event);
        $values['tags'] = $this->formatTags($destination->tags ?? [], '#');
        $values['tags_str'] = $this->formatTags($destination->tags ?? [], '');

        return preg_replace_callback(
            '/{{\s*([^{}]+?)\s*}}/',
            fn (array $match) => (string) ($values[trim($match[1])] ?? '—'),
            (string) $template,
        ) ?? (string) $template;
    }

    /** @return array<string, string> */
    private function values(WebhookEvent $event): array
    {
        $payload = $event->payload ?? [];
        $team = $event->team;
        $actor = (string) (data_get($payload, 'actor.display_name') ?: 'System');
        $appId = data_get($event->filter_context, 'mobile_app_ids.0')
            ?? data_get($payload, 'mobile_app_id');
        $app = is_string($appId) ? MobileApp::find($appId) : null;
        $values = [
            'actor.name' => $actor,
            'team.name' => $team?->name ?? 'Workspace',
            'app.name' => $app?->name ?? 'Unknown app',
            ...$this->structuredValues('app.metadata', $app?->metadata),
        ];

        if (str_starts_with($event->event_type->value, 'mobile_app.')) {
            $values = array_merge($values, [
                'app.name' => (string) (data_get($payload, 'name') ?: $app?->name ?: 'Unknown app'),
                'app.slug' => (string) (data_get($payload, 'slug') ?: $app?->slug ?: '—'),
                'app.description' => (string) (data_get($payload, 'description') ?: $app?->description ?: '—'),
                'app.url' => $app
                    ? route('accounts.apps.show', [$team, $app])
                    : route('accounts.apps.index', $team),
                ...$this->modelTags($app, 'app'),
                ...$this->structuredValues(
                    'app.metadata',
                    array_key_exists('metadata', $payload) ? $payload['metadata'] : $app?->metadata,
                ),
            ]);
        }

        if (str_starts_with($event->event_type->value, 'app_identifier.')) {
            $identifier = AppIdentifier::query()
                ->with('mobileApp')
                ->find(data_get($payload, 'id') ?? $event->aggregate_id);
            $identifierAppId = data_get($payload, 'mobile_app_id') ?: $identifier?->mobile_app_id;
            $identifierApp = $identifier?->mobileApp
                ?? (is_string($identifierAppId) ? MobileApp::find($identifierAppId) : null);
            $identifierValue = (string) (data_get($payload, 'identifier') ?: $identifier?->identifier ?: '—');
            $values = array_merge($values, [
                'app.name' => $identifierApp?->name ?? $values['app.name'],
                'identifier.name' => (string) (data_get($payload, 'name') ?: $identifier?->name ?: $identifierValue),
                'identifier.value' => $identifierValue,
                'identifier.platform' => (string) (data_get($payload, 'platform') ?: $identifier?->platform?->value ?: '—'),
                'identifier.version' => (string) (data_get($payload, 'version') ?: $identifier?->version ?: '—'),
                'identifier.url' => $identifier && $identifierApp
                    ? route('accounts.apps.identifiers.show', [$team, $identifierApp, $identifier])
                    : ($identifierApp
                        ? route('accounts.apps.identifiers.index', [$team, $identifierApp])
                        : route('accounts.apps.index', $team)),
                ...$this->modelTags($identifierApp, 'app'),
                ...$this->structuredValues('app.metadata', $identifierApp?->metadata),
            ]);
        }

        if (str_starts_with($event->event_type->value, 'build.')) {
            $build = BuildRecord::query()
                ->with(['mobileApp', 'requestedBy', 'ciCdRun'])
                ->find(data_get($payload, 'id') ?? $event->aggregate_id);
            $buildId = (string) ($build?->id ?? data_get($payload, 'id') ?? $event->aggregate_id);
            $status = (string) (data_get($payload, 'status') ?: 'pending');
            $values = array_merge($values, [
                'app.name' => $build?->mobileApp?->name ?? $values['app.name'],
                'build.number' => $buildId !== '' ? substr($buildId, 0, 8) : '—',
                'build.profile' => (string) (data_get($payload, 'profile_type') ?: $build?->profile_type?->value ?: '—'),
                'build.requested_by' => $build?->requestedBy?->name ?? $actor,
                'build.branch' => (string) (data_get($payload, 'parameters.ref')
                    ?: data_get($build?->parameters, 'ref')
                    ?: data_get($build?->ciCdRun?->request_snapshot, 'ref')
                    ?: '—'),
                'build.status' => str_replace('_', ' ', $status),
                'build.status_icon' => NotificationEventCatalog::buildStatusIcon($status),
                'build.duration' => $this->duration(
                    data_get($payload, 'started_at'),
                    data_get($payload, 'finished_at'),
                ),
                'build.url' => $build && $build->mobileApp
                    ? route('accounts.apps.builds.show', [$team, $build->mobileApp, $build])
                    : route('accounts.apps.index', $team),
                ...$this->modelTags($build, 'build'),
                ...$this->structuredValues('app.metadata', $build?->mobileApp?->metadata),
                ...$this->structuredValues('build.parameters', $build?->parameters),
            ]);
        }

        if ($event->event_type->value === 'artifact.created') {
            $artifact = Artifact::query()
                ->with('buildRecord.mobileApp')
                ->find(data_get($payload, 'id') ?? $event->aggregate_id);
            $fileName = (string) (data_get($payload, 'file.name') ?: data_get($payload, 'name') ?: 'Artifact');
            $build = $artifact?->buildRecord;
            $values = array_merge($values, [
                'app.name' => $build?->mobileApp?->name ?? $values['app.name'],
                'artifact.name' => $fileName,
                'artifact.type' => strtolower((string) pathinfo($fileName, PATHINFO_EXTENSION)) ?: 'file',
                'artifact.size' => $this->fileSize((int) data_get($payload, 'file.size', 0)),
                'artifact.download_url' => $artifact && $build?->mobileApp
                    ? route('accounts.apps.builds.artifacts.download', [$team, $build->mobileApp, $build, $artifact])
                    : route('accounts.apps.index', $team),
                'build.number' => $build?->id ? substr($build->id, 0, 8) : '—',
                ...$this->modelTags($build, 'build'),
                ...$this->structuredValues('app.metadata', $build?->mobileApp?->metadata),
                ...$this->structuredValues('build.parameters', $build?->parameters),
            ]);
        }

        if (str_starts_with($event->event_type->value, 'credential.')) {
            $credential = Credential::find(data_get($payload, 'id') ?? $event->aggregate_id);
            $expiresAt = data_get($payload, 'expires_at') ?: $credential?->expires_at?->toISOString();
            $values = array_merge($values, [
                'credential.name' => (string) (data_get($payload, 'name') ?: $credential?->name ?: 'Credential'),
                'credential.type' => (string) (data_get($payload, 'type') ?: $credential?->type?->value ?: 'credential'),
                'credential.expires_at' => $this->date($expiresAt),
                'credential.days_remaining' => (string) (data_get($payload, 'days_remaining') ?? '—'),
                'credential.url' => route('accounts.credentials.index', $team),
                ...$this->modelTags($credential, 'credential'),
            ]);
        }

        if ($event->event_type->value === 'team.invitation.created') {
            $values = array_merge($values, [
                'invitation.email' => (string) (data_get($payload, 'email') ?: '—'),
                'invitation.role' => (string) (data_get($payload, 'role') ?: 'member'),
                'invitation.url' => route('accounts.team.index', $team),
            ]);
        }

        return $values;
    }

    private function duration(?string $startedAt, ?string $finishedAt): string
    {
        if (! $startedAt || ! $finishedAt) {
            return '—';
        }

        try {
            $seconds = CarbonImmutable::parse($startedAt)->diffInSeconds(CarbonImmutable::parse($finishedAt));
        } catch (Throwable) {
            return '—';
        }

        $minutes = intdiv((int) $seconds, 60);
        $remainingSeconds = (int) $seconds % 60;

        return $minutes > 0 ? "{$minutes}m {$remainingSeconds}s" : "{$remainingSeconds}s";
    }

    private function date(?string $value): string
    {
        if (! $value) {
            return 'No expiration';
        }

        try {
            return CarbonImmutable::parse($value)->format('M j, Y');
        } catch (Throwable) {
            return '—';
        }
    }

    private function fileSize(int $bytes): string
    {
        if ($bytes <= 0) {
            return '—';
        }

        $units = ['B', 'KB', 'MB', 'GB'];
        $power = min((int) floor(log($bytes, 1024)), count($units) - 1);
        $value = $bytes / (1024 ** $power);

        return number_format($value, $power === 0 ? 0 : 1).' '.$units[$power];
    }

    /** @param list<string> $tags */
    private function formatTags(array $tags, string $prefix): string
    {
        $tags = array_filter($tags, fn (string $tag) => $tag !== '');

        return implode(' ', array_map(fn (string $tag) => $prefix.$tag, $tags));
    }

    /**
     * @return array<string, string>
     */
    private function modelTags(?object $model, string $prefix): array
    {
        $texts = $model && method_exists($model, 'tagList')
            ? collect($model->tagList())->pluck(0)->values()->all()
            : [];

        return [
            "{$prefix}.tags" => $this->formatTags($texts, '#'),
            "{$prefix}.tags_str" => $this->formatTags($texts, ''),
        ];
    }

    /** @return array<string, string> */
    private function structuredValues(string $prefix, mixed $value): array
    {
        $values = ["{$prefix}_str" => $this->stringify($value)];

        if (! is_array($value)) {
            return $values;
        }

        foreach ($value as $key => $item) {
            $segment = (string) $key;

            if (preg_match('/^[a-zA-Z0-9_-]+$/', $segment) !== 1) {
                continue;
            }

            $path = "{$prefix}.{$segment}";
            $values[$path] = $this->stringify($item);

            if (is_array($item)) {
                $values = array_merge($values, $this->nestedStructuredValues($path, $item));
            }
        }

        return $values;
    }

    /**
     * @param  array<array-key, mixed>  $value
     * @return array<string, string>
     */
    private function nestedStructuredValues(string $prefix, array $value): array
    {
        $values = [];

        foreach ($value as $key => $item) {
            $segment = (string) $key;

            if (preg_match('/^[a-zA-Z0-9_-]+$/', $segment) !== 1) {
                continue;
            }

            $path = "{$prefix}.{$segment}";
            $values[$path] = $this->stringify($item);

            if (is_array($item)) {
                $values = array_merge($values, $this->nestedStructuredValues($path, $item));
            }
        }

        return $values;
    }

    private function stringify(mixed $value): string
    {
        if ($value === null) {
            return '—';
        }

        if (is_bool($value)) {
            return $value ? 'true' : 'false';
        }

        if (is_string($value) || is_int($value) || is_float($value)) {
            return (string) $value;
        }

        $json = json_encode($value, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

        return $json === false ? '—' : $json;
    }
}

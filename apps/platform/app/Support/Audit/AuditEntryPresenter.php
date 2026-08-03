<?php

namespace App\Support\Audit;

use App\Enums\IntegrationKind;
use App\Enums\IntegrationProvider;
use App\Enums\TagAnimation;
use App\Models\ApiKey;
use App\Models\AppIdentifier;
use App\Models\Audit;
use App\Models\BuildRecord;
use App\Models\Credential;
use App\Models\Integration;
use App\Models\MobileApp;
use App\Models\NotificationDestination;
use App\Models\Team;
use App\Models\WebhookEndpoint;
use App\Support\Tags\TagRules;
use BackedEnum;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

final class AuditEntryPresenter
{
    /**
     * Audit payloads can contain large operational snapshots. Only explicitly
     * approved, non-sensitive fields are exposed to the browser.
     *
     * @var array<string, string>
     */
    private const FIELD_LABELS = [
        'status' => 'Status',
        'name' => 'Name',
        'description' => 'Description',
        'slug' => 'Slug',
        'tags' => 'Tags',
        'platform' => 'Platform',
        'identifier' => 'Identifier',
        'version' => 'Version',
        'version_code' => 'Build number',
        'profile_type' => 'Profile',
        'reporting_mode' => 'Reporting',
        'expires_at' => 'Expires',
        'provider' => 'Provider',
        'kind' => 'Type',
        'external_account_name' => 'Account',
        'last_synced_at' => 'Last synced',
        'disabled_at' => 'Disabled',
        'disconnected_at' => 'Disconnected',
        'url' => 'URL',
        'subscribed_events' => 'Events',
        'consecutive_failures' => 'Failures',
        'last_delivered_at' => 'Last delivered',
        'abilities' => 'Permissions',
        'role' => 'Role',
        'started_at' => 'Started',
        'cancel_requested_at' => 'Cancellation requested',
        'finished_at' => 'Finished',
        'file_name' => 'File',
        'file_metadata' => 'Metadata',
    ];

    /** @return array<string, mixed> */
    public static function present(Audit $audit, Team $team, bool $canManageApiKeys): array
    {
        $changes = self::changes($audit);
        $changedKeys = self::changedKeys($audit);
        $apiKey = self::teamResource($audit->relationLoaded('apiKey') ? $audit->apiKey : null, $team);

        return [
            'id' => $audit->id,
            'event' => $audit->event,
            'action_label' => self::actionLabel($audit->event, $changedKeys),
            'action_description' => self::actionDescription($audit->event, $changedKeys, $changes),
            'auditable_type' => class_basename($audit->auditable_type),
            'auditable_id' => $audit->auditable_id,
            'target' => self::target($audit, $team, $canManageApiKeys),
            'changes' => $changes,
            'source' => $audit->source,
            'source_label' => self::sourceLabel($audit->source),
            'actor' => self::actor($audit, $apiKey),
            'api_key' => $apiKey instanceof ApiKey ? [
                'id' => $apiKey->id,
                'name' => $apiKey->name,
                'href' => $canManageApiKeys
                    ? route('accounts.api-keys.index', ['team' => $team])
                    : null,
            ] : null,
            'ip_address' => $audit->ip_address,
            'user_agent' => $audit->user_agent
                ? Str::limit(preg_replace('/\s+/', ' ', $audit->user_agent) ?: '', 240)
                : null,
            'created_at' => $audit->created_at?->toISOString(),
        ];
    }

    /** @return array<string, mixed> */
    private static function actor(Audit $audit, ?Model $apiKey): array
    {
        if ($audit->user) {
            return [
                'id' => $audit->user->id,
                'name' => $audit->user->name,
                'type' => 'user',
                'detail' => $apiKey instanceof ApiKey ? "via {$apiKey->name} API key" : null,
            ];
        }

        if ($apiKey instanceof ApiKey) {
            return [
                'id' => $apiKey->id,
                'name' => $apiKey->name,
                'type' => 'api_key',
                'detail' => 'API key',
            ];
        }

        return [
            'id' => null,
            'name' => 'System',
            'type' => 'system',
            'detail' => null,
        ];
    }

    /** @return array{type: string, label: string, meta: ?string, href: ?string} */
    private static function target(Audit $audit, Team $team, bool $canManageApiKeys): array
    {
        $resource = self::teamResource(
            $audit->relationLoaded('auditable') ? $audit->auditable : null,
            $team,
        );
        $values = array_replace($audit->old_values ?? [], $audit->new_values ?? []);
        $id = (string) $audit->auditable_id;
        $shortId = self::shortId($id);
        $href = null;
        $type = Str::headline(class_basename($audit->auditable_type));
        $label = self::snapshotLabel($values) ?? "{$type} #{$shortId}";
        $meta = null;

        if ($resource instanceof MobileApp && str_starts_with($audit->event, 'file_')) {
            $type = 'File';
            $label = is_string($values['file_name'] ?? null)
                ? $values['file_name']
                : "File #{$shortId}";
            $meta = $resource->name;
            $href = route('accounts.apps.media.index', ['team' => $team, 'mobileApp' => $resource]);
        } elseif ($resource instanceof MobileApp) {
            $type = 'App';
            $label = $resource->name;
            $href = route('accounts.apps.show', ['team' => $team, 'mobileApp' => $resource]);
        } elseif ($resource instanceof AppIdentifier) {
            $type = 'App identifier';
            $label = $resource->name ?: $resource->identifier;
            $meta = self::join([
                $resource->mobileApp?->name,
                self::humanizeToken(self::enumValue($resource->platform)),
                $label !== $resource->identifier ? $resource->identifier : null,
            ]);
            if ($resource->mobileApp) {
                $href = route('accounts.apps.identifiers.show', [
                    'team' => $team,
                    'mobileApp' => $resource->mobileApp,
                    'appIdentifier' => $resource,
                ]);
            }
        } elseif ($resource instanceof BuildRecord) {
            $type = 'Build';
            $label = "Build #{$shortId}";
            $identifier = $resource->appIdentifier;
            $meta = self::join([
                $resource->mobileApp?->name,
                $identifier?->name ?: $identifier?->identifier,
            ]);
            if ($resource->mobileApp) {
                $href = route('accounts.apps.builds.show', [
                    'team' => $team,
                    'mobileApp' => $resource->mobileApp,
                    'buildRecord' => $resource,
                ]);
            }
        } elseif ($resource instanceof Credential) {
            $type = 'Credential';
            $label = $resource->name;
            $identifier = $resource->appIdentifiers->first()
                ?? $resource->profiles->first()?->appIdentifier;
            $meta = self::join([
                self::humanizeToken(self::enumValue($resource->type)),
                $identifier?->mobileApp?->name,
            ]);
            if ($identifier?->mobileApp) {
                $href = route('accounts.apps.identifiers.show', [
                    'team' => $team,
                    'mobileApp' => $identifier->mobileApp,
                    'appIdentifier' => $identifier,
                ]);
            }
        } elseif ($resource instanceof Integration) {
            $type = 'Integration';
            $label = $resource->name;
            $meta = self::join([
                $resource->provider?->label(),
                $resource->external_account_name,
            ]);
            $href = self::integrationUrl($resource, $team);
        } elseif ($resource instanceof NotificationDestination) {
            $type = 'Notification destination';
            $label = $resource->integration?->name ?: "Notification destination #{$shortId}";
            $meta = self::join([
                $resource->integration?->provider?->label(),
                $resource->destination,
            ]);
            $href = self::notificationDestinationUrl($resource->id, $team);
        } elseif ($resource instanceof WebhookEndpoint) {
            $type = 'Webhook endpoint';
            $label = $resource->integration?->name ?: (self::snapshotLabel($values) ?? "Endpoint #{$shortId}");
            $meta = $resource->url;
            $href = route('accounts.integrations.webhooks.index', ['team' => $team]);
        } elseif ($resource instanceof ApiKey) {
            $type = 'API key';
            $label = $resource->name;
            $href = $canManageApiKeys
                ? route('accounts.api-keys.index', ['team' => $team])
                : null;
        } elseif ($resource instanceof Team) {
            $type = str_starts_with($audit->event, 'member_') ? 'Team member' : 'Team';
            $label = str_starts_with($audit->event, 'member_') ? 'Team membership' : $resource->name;
            $meta = $resource->name;
            $href = route('accounts.team.index', ['team' => $team]);
        }

        if ($audit->event === 'deleted') {
            $href = null;
        }

        return compact('type', 'label', 'meta', 'href');
    }

    private static function integrationUrl(Integration $integration, Team $team): string
    {
        if ($integration->kind === IntegrationKind::Notification) {
            $destinationId = $integration->external_id
                ?: ($integration->relationLoaded('notificationDestination')
                    ? $integration->notificationDestination?->id
                    : null);

            return $destinationId
                ? self::notificationDestinationUrl($destinationId, $team)
                : route('accounts.integrations.notifications.index', ['team' => $team]);
        }

        if ($integration->kind === IntegrationKind::Webhook) {
            return route('accounts.integrations.webhooks.index', ['team' => $team]);
        }

        if ($integration->provider === IntegrationProvider::Expo) {
            return route('accounts.integrations.eas.index', ['team' => $team]);
        }

        return route('accounts.integrations.index', ['team' => $team]);
    }

    private static function notificationDestinationUrl(string $destinationId, Team $team): string
    {
        return route('accounts.integrations.notifications.index', [
            'team' => $team,
            'selected' => $destinationId,
        ]);
    }

    private static function teamResource(mixed $resource, Team $team): ?Model
    {
        if (! $resource instanceof Model) {
            return null;
        }

        $belongsToTeam = match (true) {
            $resource instanceof Team => $resource->id === $team->id,
            $resource instanceof AppIdentifier => $resource->mobileApp?->team_id === $team->id,
            default => isset($resource->team_id) && $resource->team_id === $team->id,
        };

        return $belongsToTeam ? $resource : null;
    }

    /** @return list<array{key: string, field: string, mode: string, from: ?string, to: ?string}> */
    private static function changes(Audit $audit): array
    {
        $oldValues = $audit->old_values ?? [];
        $newValues = $audit->new_values ?? [];
        $changedKeys = self::changedKeys($audit);
        $changes = [];

        foreach (array_keys(self::FIELD_LABELS) as $key) {
            if (! in_array($key, $changedKeys, true)) {
                continue;
            }

            $hasOld = array_key_exists($key, $oldValues);
            $hasNew = array_key_exists($key, $newValues);
            $change = [
                'key' => $key,
                'field' => self::FIELD_LABELS[$key],
                'mode' => ! $hasOld ? 'added' : (! $hasNew ? 'removed' : 'changed'),
                'from' => $hasOld ? self::formatValue($key, $oldValues[$key]) : null,
                'to' => $hasNew ? self::formatValue($key, $newValues[$key]) : null,
            ];

            if ($key === 'tags') {
                $change['from_tags'] = $hasOld ? self::normalizeTags($oldValues[$key]) : [];
                $change['to_tags'] = $hasNew ? self::normalizeTags($newValues[$key]) : [];
            }

            $changes[] = $change;
        }

        return $changes;
    }

    /** @return list<string> */
    private static function changedKeys(Audit $audit): array
    {
        $oldValues = $audit->old_values ?? [];
        $newValues = $audit->new_values ?? [];
        $keys = array_values(array_unique([...array_keys($oldValues), ...array_keys($newValues)]));

        return array_values(array_filter($keys, function (string $key) use ($oldValues, $newValues): bool {
            $hasOld = array_key_exists($key, $oldValues);
            $hasNew = array_key_exists($key, $newValues);

            return $hasOld !== $hasNew || ($hasOld && $oldValues[$key] !== $newValues[$key]);
        }));
    }

    /** @param list<string> $changedKeys */
    private static function actionLabel(string $event, array $changedKeys): string
    {
        if ($event !== 'updated') {
            return match ($event) {
                'created' => 'Created',
                'deleted' => 'Deleted',
                'restored' => 'Restored',
                'member_added' => 'Member added',
                'member_removed' => 'Member removed',
                'member_role_updated' => 'Role changed',
                'file_created' => 'File added',
                'file_metadata_updated' => 'File metadata updated',
                'file_deleted' => 'File deleted',
                default => Str::headline($event),
            };
        }

        return match (true) {
            in_array('status', $changedKeys, true) => 'Status changed',
            in_array('name', $changedKeys, true) => 'Renamed',
            in_array('tags', $changedKeys, true) => 'Tags updated',
            in_array('subscribed_events', $changedKeys, true) => 'Subscriptions changed',
            in_array('url', $changedKeys, true) => 'Endpoint changed',
            count(array_diff($changedKeys, ['log_output', 'logs_updated_at'])) === 0 => 'Logs updated',
            in_array('settings', $changedKeys, true) => 'Settings updated',
            default => 'Updated',
        };
    }

    /**
     * @param  list<string>  $changedKeys
     * @param  list<array<string, mixed>>  $changes
     */
    private static function actionDescription(string $event, array $changedKeys, array $changes): ?string
    {
        if ($changes !== []) {
            return null;
        }

        if (array_intersect($changedKeys, ['log_output', 'logs_updated_at']) !== []) {
            return 'New build output was recorded.';
        }

        if (in_array('settings', $changedKeys, true)) {
            return 'Provider settings changed.';
        }

        return match ($event) {
            'created' => 'The resource was created.',
            'deleted' => 'The resource was deleted.',
            default => $changedKeys === [] ? null : 'Protected or internal metadata changed.',
        };
    }

    private static function formatValue(string $key, mixed $value): string
    {
        if ($value === null || $value === '') {
            return 'Not set';
        }

        if ($key === 'tags') {
            $count = count(self::normalizeTags($value));

            return match ($count) {
                0 => 'None',
                1 => '1 tag',
                default => "{$count} tags",
            };
        }

        if ($key === 'file_metadata' && is_array($value)) {
            if ($value === []) {
                return 'None';
            }

            $json = json_encode($value, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

            return $json === false ? 'Changed' : Str::limit($json, 120);
        }

        if (is_bool($value)) {
            return $value ? 'Yes' : 'No';
        }

        if (is_array($value)) {
            if ($value === []) {
                return 'None';
            }

            $items = array_values(array_filter(array_map(
                fn (mixed $item) => is_scalar($item) ? self::humanizeToken((string) $item) : null,
                $value,
            )));

            return $items === [] ? 'Changed' : Str::limit(implode(', ', $items), 120);
        }

        $value = self::enumValue($value);
        $string = preg_replace('/\s+/', ' ', trim((string) $value)) ?: '';

        if (in_array($key, [
            'status',
            'platform',
            'profile_type',
            'reporting_mode',
            'provider',
            'kind',
            'role',
        ], true)) {
            $string = self::humanizeToken($string);
        }

        return Str::limit($string, 120);
    }

    /** @return list<array{label: string, color: ?string}> */
    private static function normalizeTags(mixed $value): array
    {
        if (is_string($value)) {
            $decoded = json_decode($value, true);

            if (json_last_error() === JSON_ERROR_NONE) {
                $value = $decoded;
            }
        }

        if (! is_array($value)) {
            return [];
        }

        $tags = [];
        $seen = [];

        foreach ($value as $tag) {
            if (! is_array($tag)) {
                continue;
            }

            $label = is_string($tag[0] ?? null) ? trim($tag[0]) : '';
            $key = mb_strtolower($label);

            if ($label === '' || isset($seen[$key])) {
                continue;
            }

            $rawColor = $tag[1] ?? null;
            $color = is_string($rawColor) && preg_match(TagRules::HEX_REGEX, $rawColor)
                ? strtolower($rawColor)
                : null;

            $seen[$key] = true;
            $tags[] = [
                'label' => Str::limit($label, TagRules::MAX_TEXT_LENGTH),
                'color' => $color,
                // Surfaced as text, not as a live animation: animating a
                // historical record in the timeline would just be noise.
                'animation' => TagAnimation::tryFromLoose($tag[2] ?? null)?->value,
            ];
        }

        return $tags;
    }

    private static function enumValue(mixed $value): mixed
    {
        return $value instanceof BackedEnum ? $value->value : $value;
    }

    private static function humanizeToken(mixed $value): ?string
    {
        if (! is_scalar($value) || (string) $value === '') {
            return null;
        }

        return match ((string) $value) {
            'api' => 'API',
            'ci_cd' => 'CI/CD',
            'ios' => 'iOS',
            'appstore' => 'App Store',
            'github' => 'GitHub',
            'gitlab' => 'GitLab',
            'http' => 'HTTP',
            default => Str::headline((string) $value),
        };
    }

    private static function sourceLabel(?string $source): string
    {
        return match ($source) {
            'api' => 'API',
            'ci_cd' => 'CI/CD',
            'web' => 'Web',
            'system' => 'System',
            null, '' => 'Unknown',
            default => Str::headline($source),
        };
    }

    /** @param array<string, mixed> $values */
    private static function snapshotLabel(array $values): ?string
    {
        foreach (['name', 'identifier', 'url'] as $key) {
            $value = $values[$key] ?? null;

            if (is_string($value) && $value !== '') {
                return Str::limit($value, 80);
            }
        }

        return null;
    }

    /** @param list<?string> $parts */
    private static function join(array $parts): ?string
    {
        $parts = array_values(array_filter($parts, fn (?string $part) => $part !== null && $part !== ''));

        return $parts === [] ? null : implode(' · ', $parts);
    }

    private static function shortId(string $id): string
    {
        return Str::length($id) > 8 ? Str::substr($id, 0, 8) : $id;
    }
}

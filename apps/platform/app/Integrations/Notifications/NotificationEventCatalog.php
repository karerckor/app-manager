<?php

namespace App\Integrations\Notifications;

final class NotificationEventCatalog
{
    /** @var array<string, string> */
    private const BUILD_STATUS_ICONS = [
        'completed' => '✅',
        'failed' => '❌',
        'cancelled' => '⚪',
        'cancel_requested' => '⚪',
        'running' => '🚀',
    ];

    /** @return list<array<string, mixed>> */
    public static function all(): array
    {
        return [
            self::event(
                'mobile_app.created',
                'Application created',
                'Application events',
                'A new application is added to the workspace.',
                "📱 {{actor.name}} created {{app.name}}\n{{app.url}}",
                [
                    self::variable('app.name', 'App name', 'The new application display name.', 'Atlas Mobile'),
                    self::variable('app.slug', 'App slug', 'The application URL slug.', 'atlas-mobile'),
                    self::variable('app.description', 'Description', 'The application description, when available.', 'Customer mobile application'),
                    self::variable('app.url', 'App URL', 'A direct link to the application.', 'https://manager.example/apps/atlas-mobile'),
                    self::variable('actor.name', 'Created by', 'The person or automation that created the application.', 'Maya Chen'),
                    ...self::appTagVariables(),
                ],
            ),
            self::event(
                'mobile_app.deleted',
                'Application deleted',
                'Application events',
                'An application is removed from the workspace.',
                '🗑️ {{actor.name}} deleted {{app.name}} ({{app.slug}})',
                [
                    self::variable('app.name', 'App name', 'The deleted application display name.', 'Atlas Mobile'),
                    self::variable('app.slug', 'App slug', 'The deleted application URL slug.', 'atlas-mobile'),
                    self::variable('actor.name', 'Deleted by', 'The person or automation that deleted the application.', 'Maya Chen'),
                    ...self::appTagVariables(),
                ],
            ),
            self::event(
                'app_identifier.created',
                'Identifier added',
                'App Identifiers and Platforms',
                'A platform identifier is added to an application.',
                "➕ {{identifier.platform}} identifier {{identifier.value}} added to {{app.name}} by {{actor.name}}\n{{identifier.url}}",
                self::identifierVariables('Added by'),
            ),
            self::event(
                'app_identifier.updated',
                'Identifier updated',
                'App Identifiers and Platforms',
                'A platform identifier or its version is changed.',
                "✏️ {{identifier.platform}} identifier {{identifier.value}} updated for {{app.name}} by {{actor.name}}\n{{identifier.url}}",
                self::identifierVariables('Updated by'),
            ),
            self::event(
                'app_identifier.deleted',
                'Identifier removed',
                'App Identifiers and Platforms',
                'A platform identifier is removed from an application.',
                '➖ {{identifier.platform}} identifier {{identifier.value}} removed from {{app.name}} by {{actor.name}}',
                self::identifierVariables('Removed by'),
            ),
            self::event(
                'credential.created',
                'Credential added',
                'Credentials',
                'A credential is added to Credentials Vault.',
                "🔐 {{actor.name}} added {{credential.name}} ({{credential.type}})\n{{credential.url}}",
                self::credentialVariables('Created by'),
            ),
            self::event(
                'credential.updated',
                'Credential updated',
                'Credentials',
                'A credential, its secret, or its assignments are changed.',
                "🔄 {{actor.name}} updated {{credential.name}} ({{credential.type}})\n{{credential.url}}",
                self::credentialVariables('Updated by'),
            ),
            self::event(
                'credential.deleted',
                'Credential deleted',
                'Credentials',
                'A credential is removed from Credentials Vault.',
                '🗑️ {{actor.name}} deleted {{credential.name}} ({{credential.type}})',
                self::credentialVariables('Deleted by'),
            ),
            self::event(
                'credential.expiring_30_days',
                'Credential expires in 30 days',
                'Credentials',
                'A credential is within 30 days of its expiration date.',
                "⏳ {{credential.name}} expires in {{credential.days_remaining}} days · {{credential.expires_at}}\n{{credential.url}}",
                self::credentialExpiryVariables('30'),
            ),
            self::event(
                'credential.expiring_7_days',
                'Credential expires in 7 days',
                'Credentials',
                'A credential is within 7 days of its expiration date.',
                "⚠️ {{credential.name}} expires in {{credential.days_remaining}} days · {{credential.expires_at}}\n{{credential.url}}",
                self::credentialExpiryVariables('7'),
            ),
            self::event(
                'credential.expiring_1_day',
                'Credential expires tomorrow',
                'Credentials',
                'A credential is within 24 hours of its expiration date.',
                "🚨 {{credential.name}} expires in {{credential.days_remaining}} day · {{credential.expires_at}}\n{{credential.url}}",
                self::credentialExpiryVariables('1'),
            ),
            self::event(
                'credential.expired',
                'Credential expired',
                'Credentials',
                'A credential has reached its expiration date.',
                "🚨 {{credential.name}} expired on {{credential.expires_at}}\n{{credential.url}}",
                self::credentialExpiryVariables('0'),
            ),
            self::event(
                'credential.verification_failed',
                'Credential verification failed',
                'Credentials',
                'A build could not resolve a credential referenced in your vault.',
                "🚨 {{credential.name}} failed vault verification\n{{credential.url}}",
                self::credentialVariables('Reported by'),
            ),
            self::event(
                'build.created',
                'Build requested',
                'Builds',
                'A new build enters the queue.',
                'Build #{{build.number}} for {{app.name}} was requested by {{build.requested_by}} · {{build.profile}}',
                [
                    self::variable('app.name', 'App name', 'The application being built.', 'Atlas Mobile'),
                    self::variable('build.number', 'Build number', 'The workspace build sequence number.', '482'),
                    self::variable('build.profile', 'Profile', 'The selected build profile.', 'production'),
                    self::variable('build.requested_by', 'Requested by', 'The person or automation that requested the build.', 'Maya Chen'),
                    self::variable('build.url', 'Build URL', 'A direct link to the build details.', 'https://manager.example/builds/482'),
                    ...self::buildTagVariables(),
                    ...self::appMetadataVariables(),
                ],
            ),
            self::event(
                'build.started',
                'Build started',
                'Builds',
                'A runner starts working on a build.',
                "🚀 {{app.name}} build #{{build.number}} started · {{build.profile}}\n{{build.url}}",
                [
                    self::variable('app.name', 'App name', 'The application being built.', 'Atlas Mobile'),
                    self::variable('build.number', 'Build number', 'The workspace build sequence number.', '482'),
                    self::variable('build.profile', 'Profile', 'The selected build profile.', 'production'),
                    self::variable('build.branch', 'Branch', 'The source branch or ref.', 'release/4.8'),
                    self::variable('build.url', 'Build URL', 'A direct link to the build details.', 'https://manager.example/builds/482'),
                    ...self::buildTagVariables(),
                    ...self::appMetadataVariables(),
                ],
            ),
            self::event(
                'build.completed',
                'Build completed',
                'Builds',
                'A build succeeds, fails, or is cancelled.',
                "{{build.status_icon}} {{app.name}} build #{{build.number}} {{build.status}} in {{build.duration}}\n{{build.url}}",
                [
                    self::variable('app.name', 'App name', 'The application that was built.', 'Atlas Mobile'),
                    self::variable('build.number', 'Build number', 'The workspace build sequence number.', '482'),
                    self::variable('build.status', 'Status', 'The final build status.', 'completed'),
                    self::variable(
                        'build.status_icon',
                        'Status icon',
                        'An icon matched to the final status.',
                        '✅',
                        self::buildCompletedStatusIconOptions(),
                    ),
                    self::variable('build.duration', 'Duration', 'The human-readable build duration.', '8m 42s'),
                    self::variable('build.url', 'Build URL', 'A direct link to the build details.', 'https://manager.example/builds/482'),
                    ...self::buildTagVariables(),
                    ...self::appMetadataVariables(),
                ],
            ),
            self::event(
                'artifact.created',
                'Artifact ready',
                'Builds',
                'A downloadable artifact is attached to a build.',
                "📦 {{artifact.name}} is ready for {{app.name}} · {{artifact.size}}\n{{artifact.download_url}}",
                [
                    self::variable('app.name', 'App name', 'The application the artifact belongs to.', 'Atlas Mobile'),
                    self::variable('artifact.name', 'File name', 'The artifact file name.', 'atlas-4.8.0.ipa'),
                    self::variable('artifact.type', 'File type', 'The artifact format.', 'ipa'),
                    self::variable('artifact.size', 'File size', 'The human-readable file size.', '54.2 MB'),
                    self::variable('artifact.download_url', 'Download URL', 'A direct download link.', 'https://manager.example/artifacts/atlas-4.8.0.ipa'),
                    self::variable('build.number', 'Build number', 'The source build sequence number.', '482'),
                    ...self::buildTagVariables(),
                    ...self::appMetadataVariables(),
                ],
            ),
            self::event(
                'team.invitation.created',
                'Team invitation sent',
                'Team Management',
                'Someone is invited to join the workspace.',
                '👋 {{actor.name}} invited {{invitation.email}} to join {{team.name}} as {{invitation.role}}',
                [
                    self::variable('team.name', 'Workspace', 'The workspace display name.', 'Atlas Team'),
                    self::variable('invitation.email', 'Invitee email', 'The invited email address.', 'alex@example.com'),
                    self::variable('invitation.role', 'Role', 'The role granted by the invitation.', 'contributor'),
                    self::variable('actor.name', 'Invited by', 'The person who sent the invitation.', 'Maya Chen'),
                    self::variable('invitation.url', 'Invitation URL', 'A direct link to accept the invitation.', 'https://manager.example/invitations/accept'),
                ],
            ),
        ];
    }

    /** @return list<array<string, mixed>> */
    private static function identifierVariables(string $actorLabel): array
    {
        return [
            self::variable('app.name', 'App name', 'The application this identifier belongs to.', 'Atlas Mobile'),
            self::variable('identifier.name', 'Identifier name', 'The optional display name.', 'Atlas Mobile iOS'),
            self::variable('identifier.value', 'Identifier', 'The bundle ID, package name, or web domain.', 'com.example.atlas'),
            self::variable('identifier.platform', 'Platform', 'The identifier platform.', 'ios'),
            self::variable('identifier.version', 'Version', 'The configured application version, when available.', '4.8.0'),
            self::variable('identifier.url', 'Identifier URL', 'A direct link to the identifier settings.', 'https://manager.example/apps/atlas-mobile/identifiers/ios'),
            self::variable('actor.name', $actorLabel, 'The person or automation that made the change.', 'Maya Chen'),
            ...self::appTagVariables(),
        ];
    }

    private static function appTagVariables(): array
    {
        return [
            self::variable('app.tags', 'App tags', 'App tags prefixed with #, separated by spaces.', '#ios #production'),
            self::variable('app.tags_str', 'App tags (plain)', 'App tags as plain text, separated by spaces.', 'ios production'),
            ...self::appMetadataVariables(),
        ];
    }

    /** @return list<array<string, mixed>> */
    private static function appMetadataVariables(): array
    {
        return [
            self::dynamicVariable(
                'app.metadata.owner.name',
                'app.metadata.*',
                'app.metadata.',
                'App metadata field',
                'A value at any dotted path in the application metadata. Edit the inserted example path to match your JSON.',
                'Mobile Platform',
            ),
            self::variable(
                'app.metadata_str',
                'App metadata (JSON)',
                'The complete application metadata object as compact JSON.',
                '{"owner":{"name":"Mobile Platform"},"tier":"production"}',
            ),
        ];
    }

    private static function buildTagVariables(): array
    {
        return [
            self::variable('build.tags', 'Build tags', 'Build tags prefixed with #, separated by spaces.', '#release #hotfix'),
            self::variable('build.tags_str', 'Build tags (plain)', 'Build tags as plain text, separated by spaces.', 'release hotfix'),
            self::dynamicVariable(
                'build.parameters.channel',
                'build.parameters.*',
                'build.parameters.',
                'Launch parameter',
                'A value at any dotted path in the build Launch parameters. Edit the inserted example path and send only to trusted destinations.',
                'internal',
            ),
            self::variable(
                'build.parameters_str',
                'Launch parameters (JSON)',
                'The complete build Launch parameters object as compact JSON. Send only to trusted destinations.',
                '{"channel":"internal","flags":{"minify":true}}',
            ),
        ];
    }

    private static function credentialTagVariables(): array
    {
        return [
            self::variable('credential.tags', 'Credential tags', 'Credential tags prefixed with #, separated by spaces.', '#ios #certificate'),
            self::variable('credential.tags_str', 'Credential tags (plain)', 'Credential tags as plain text, separated by spaces.', 'ios certificate'),
        ];
    }

    /** @return list<array<string, mixed>> */
    private static function credentialVariables(string $actorLabel): array
    {
        return [
            self::variable('app.name', 'App name', 'The app linked to this credential, when any.', 'Atlas Mobile'),
            self::variable('credential.name', 'Credential name', 'The credential display name.', 'App Store Certificate'),
            self::variable('credential.type', 'Credential type', 'The credential category.', 'ios_certificate'),
            self::variable('credential.expires_at', 'Expiration date', 'The expiration date, when available.', 'Aug 31, 2026'),
            self::variable('credential.url', 'Credential URL', 'A link to Credentials Vault.', 'https://manager.example/credentials'),
            self::variable('actor.name', $actorLabel, 'The person or automation that made the change.', 'Maya Chen'),
            ...self::credentialTagVariables(),
            ...self::appMetadataVariables(),
        ];
    }

    /** @return list<array<string, mixed>> */
    private static function credentialExpiryVariables(string $daysRemaining): array
    {
        return [
            self::variable('app.name', 'App name', 'The app linked to this credential, when any.', 'Atlas Mobile'),
            self::variable('credential.name', 'Credential name', 'The expiring credential display name.', 'App Store Certificate'),
            self::variable('credential.type', 'Credential type', 'The credential category.', 'ios_certificate'),
            self::variable('credential.expires_at', 'Expiration date', 'The credential expiration date.', 'Aug 31, 2026'),
            self::variable('credential.days_remaining', 'Days remaining', 'The reminder milestone for this event.', $daysRemaining),
            self::variable('credential.url', 'Credential URL', 'A link to Credentials Vault.', 'https://manager.example/credentials'),
            ...self::credentialTagVariables(),
            ...self::appMetadataVariables(),
        ];
    }

    /** @return list<string> */
    public static function values(): array
    {
        return array_column(self::all(), 'key');
    }

    /** @return array<string, string> */
    public static function defaultTemplates(): array
    {
        $templates = [];

        foreach (self::all() as $event) {
            $templates[$event['key']] = $event['default_template'];
        }

        return $templates;
    }

    /** @return array<string, mixed>|null */
    public static function find(string $eventKey): ?array
    {
        return collect(self::all())->firstWhere('key', $eventKey);
    }

    public static function renderExample(string $eventKey, string $template, array $tags = []): string
    {
        $event = self::find($eventKey);

        if (! $event) {
            return $template;
        }

        $examples = collect($event['variables'])
            ->mapWithKeys(fn (array $variable) => [$variable['key'] => $variable['example']])
            ->all();

        $tags = array_filter($tags, fn (string $tag) => $tag !== '');
        $examples['tags'] = implode(' ', array_map(fn (string $tag) => '#'.$tag, $tags));
        $examples['tags_str'] = implode(' ', $tags);

        return preg_replace_callback(
            '/{{\s*([^{}]+?)\s*}}/',
            function (array $match) use ($examples, $event): string {
                $key = trim($match[1]);

                if (array_key_exists($key, $examples)) {
                    return (string) $examples[$key];
                }

                $dynamic = collect($event['variables'])
                    ->first(fn (array $variable) => self::matchesDynamicVariable($key, $variable));

                return $dynamic ? (string) $dynamic['example'] : $match[0];
            },
            $template,
        ) ?? $template;
    }

    public static function buildStatusIcon(string $status): string
    {
        return self::BUILD_STATUS_ICONS[$status] ?? '🔔';
    }

    /** @return list<array{label: string, value: string}> */
    private static function buildCompletedStatusIconOptions(): array
    {
        return array_map(
            fn (string $status) => [
                'label' => $status,
                'value' => self::buildStatusIcon($status),
            ],
            ['completed', 'failed', 'cancelled'],
        );
    }

    /** @return list<string> */
    public static function invalidTemplateVariables(string $eventKey, string $template): array
    {
        $event = self::find($eventKey);

        if (! $event) {
            return [];
        }

        preg_match_all('/{{\s*([^{}]+?)\s*}}/', $template, $matches);
        $variables = array_merge($event['variables'], self::globalVariables());
        $allowed = array_column($variables, 'key');

        return collect($matches[1] ?? [])
            ->map(fn (string $key) => trim($key))
            ->unique()
            ->reject(fn (string $key) => in_array($key, $allowed, true)
                || collect($variables)->contains(
                    fn (array $variable) => self::matchesDynamicVariable($key, $variable),
                ))
            ->values()
            ->all();
    }

    /** @return list<array<string, mixed>> */
    public static function globalVariables(): array
    {
        return [
            self::variable('tags', 'Tags', 'All tags prefixed with #, separated by spaces.', '#urgent #prod'),
            self::variable('tags_str', 'Tags (plain)', 'All tags as plain text, separated by spaces.', 'urgent prod'),
        ];
    }

    /** @param list<array<string, string>> $variables */
    private static function event(
        string $key,
        string $label,
        string $group,
        string $description,
        string $defaultTemplate,
        array $variables,
    ): array {
        return compact('key', 'label', 'group', 'description', 'variables') + [
            'default_template' => $defaultTemplate,
        ];
    }

    /**
     * @param  list<array{label: string, value: string}>  $options
     * @return array<string, mixed>
     */
    private static function variable(
        string $key,
        string $label,
        string $description,
        string $example,
        array $options = [],
    ): array {
        return compact('key', 'label', 'description', 'example')
            + ($options === [] ? [] : ['options' => $options]);
    }

    /** @return array<string, mixed> */
    private static function dynamicVariable(
        string $key,
        string $pattern,
        string $prefix,
        string $label,
        string $description,
        string $example,
    ): array {
        return compact('key', 'pattern', 'prefix', 'label', 'description', 'example');
    }

    /** @param array<string, mixed> $variable */
    private static function matchesDynamicVariable(string $key, array $variable): bool
    {
        $prefix = $variable['prefix'] ?? null;

        if (! is_string($prefix) || ! str_starts_with($key, $prefix)) {
            return false;
        }

        $path = substr($key, strlen($prefix));

        return preg_match('/^[a-zA-Z0-9_-]+(?:\.[a-zA-Z0-9_-]+)*$/', $path) === 1;
    }
}

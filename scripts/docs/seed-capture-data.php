<?php

/*
 * Demo data for capturing CLI documentation output.
 *
 * The existing DemoSeeder names apps by content hash (app-5aa64369d1870543ad42cf3e),
 * which reads badly in documentation. Captured tables cannot be prettified after the
 * fact either: format_table aligns columns to value widths, so editing a slug in a
 * captured file breaks the alignment of every row.
 *
 * This script is additive and idempotent. It creates a dedicated team of its own —
 * never reusing an existing one — so captured output can never contain another team's
 * app names. That isolation is the point: "appmctl apps list" prints every app in the
 * team, so capturing against a working team would publish whatever it happens to hold.
 *
 * Run it inside a booted application:
 *
 *   php artisan tinker --execute="require '/abs/path/scripts/docs/seed-capture-data.php';"
 */

use App\Enums\BuildReportingMode;
use App\Enums\BuildStatus;
use App\Enums\CredentialType;
use App\Enums\IntegrationKind;
use App\Enums\IntegrationProvider;
use App\Enums\IntegrationStatus;
use App\Enums\Platform;
use App\Enums\ProfileType;
use App\Enums\TeamRole;
use App\Enums\WebhookDeliveryStatus;
use App\Models\Artifact;
use App\Models\BuildRecord;
use App\Models\CiCdPipeline;
use App\Models\Credential;
use App\Models\Integration;
use App\Models\MobileApp;
use App\Models\NotificationDestination;
use App\Models\Team;
use App\Models\TeamInvitation;
use App\Models\User;
use App\Models\WebhookDelivery;
use App\Models\WebhookEndpoint;
use App\Models\WebhookEvent;
use App\Support\Apps\AppFileLibrary;
use App\Support\Audit\ActorContext;
use App\Support\Audit\ActorContextManager;

$out = static function (string $line): void {
    fwrite(STDOUT, $line.PHP_EOL);
};

const DOCS_TEAM_NAME = 'Acme Mobile';
const DOCS_USER_EMAIL = 'docs-capture@example.com';

$user = User::firstOrCreate(
    ['email' => DOCS_USER_EMAIL],
    ['name' => 'Docs Capture', 'password' => 'DocsCapture123!'],
);

$team = Team::query()
    ->where('owner_id', $user->id)
    ->where('name', DOCS_TEAM_NAME)
    ->first();

if (! $team) {
    $team = $user->teams()->create(
        ['name' => DOCS_TEAM_NAME, 'owner_id' => $user->id],
        ['role' => 'owner'],
    );
    $user->update(['current_team_id' => $team->id]);
}
$out("team: {$team->slug}");

$app = MobileApp::firstOrCreate(
    ['team_id' => $team->id, 'slug' => 'customer-app'],
    [
        'name' => 'Customer App',
        'description' => 'Consumer-facing mobile client.',
    ],
);
$out("app: {$app->slug}");

// --- identifiers -----------------------------------------------------------

$ios = $app->appIdentifiers()->firstOrCreate(
    ['platform' => Platform::Ios, 'identifier' => 'com.acme.customer'],
    ['name' => 'Customer App (iOS)', 'version' => '1.4.0'],
);

$android = $app->appIdentifiers()->firstOrCreate(
    ['platform' => Platform::Android, 'identifier' => 'com.acme.customer'],
    ['name' => 'Customer App (Android)', 'version' => '1.4.0', 'version_code' => 1408],
);

$web = $app->appIdentifiers()->firstOrCreate(
    ['platform' => Platform::Web, 'identifier' => 'customer.acme.example'],
    ['name' => 'Customer Web', 'version' => '1.4.0'],
);
$out("identifiers: {$ios->id} {$android->id} {$web->id}");

$androidProfile = $android->profiles()->firstOrCreate(
    ['type' => ProfileType::Production],
    ['name' => 'Production'],
);
$iosProfile = $ios->profiles()->firstOrCreate(
    ['type' => ProfileType::AppStore],
    ['name' => 'App Store'],
);

// --- credentials -----------------------------------------------------------

$keystore = Credential::firstOrCreate(
    [
        'team_id' => $team->id,
        'type' => CredentialType::AndroidKeystore,
        'name' => 'Customer App Keystore',
    ],
    ['payload' => ['store_password' => 'docs-demo', 'key_alias' => 'customer', 'key_password' => 'docs-demo']],
);
$androidProfile->credentials()->syncWithoutDetaching([$keystore->id]);

$certificate = Credential::firstOrCreate(
    [
        'team_id' => $team->id,
        'type' => CredentialType::IosCertificate,
        'name' => 'Customer App Distribution Certificate',
    ],
    ['payload' => ['password' => 'docs-demo']],
);
$iosProfile->credentials()->syncWithoutDetaching([$certificate->id]);
$out("credentials: {$keystore->id} {$certificate->id}");

// --- builds ----------------------------------------------------------------

$builds = [
    [
        'uuid' => '10000000-0000-4000-8000-000000000001',
        'identifier' => $android,
        'profile' => ProfileType::Production,
        'status' => BuildStatus::Completed,
        'mode' => BuildReportingMode::Manual,
        'tags' => [['android', '#3ddc84'], ['1.4.0', '#f59e0b']],
        'started' => 180,
        'finished' => 174,
        'log' => [
            '[1/5] Restoring dependencies...',
            '[2/5] Compiling sources...',
            '[3/5] Bundling assets...',
            '[4/5] Signing AAB with the production keystore...',
            '[5/5] Build completed in 5m 42s.',
        ],
    ],
    [
        'uuid' => '10000000-0000-4000-8000-000000000002',
        'identifier' => $ios,
        'profile' => ProfileType::AppStore,
        'status' => BuildStatus::Running,
        'mode' => BuildReportingMode::Manual,
        'tags' => [['ios', '#3b82f6'], ['1.4.0', '#f59e0b']],
        'started' => 4,
        'finished' => null,
        'log' => ['[1/5] Restoring dependencies...', '[2/5] Compiling sources...'],
    ],
    [
        'uuid' => '10000000-0000-4000-8000-000000000003',
        'identifier' => $android,
        'profile' => ProfileType::Production,
        'status' => BuildStatus::Failed,
        'mode' => BuildReportingMode::Automatic,
        'tags' => [['android', '#3ddc84'], ['nightly', '#a855f7']],
        'started' => 1440,
        'finished' => 1436,
        'log' => [
            '[1/5] Restoring dependencies...',
            '[2/5] Compiling sources...',
            'error: unresolved reference: PaymentSheet',
            'Build failed after 3m 51s.',
        ],
    ],
    [
        'uuid' => '10000000-0000-4000-8000-000000000004',
        'identifier' => $ios,
        'profile' => ProfileType::AppStore,
        'status' => BuildStatus::Pending,
        'mode' => BuildReportingMode::Eas,
        'tags' => [['ios', '#3b82f6'], ['eas', '#7c3aed']],
        'started' => null,
        'finished' => null,
        'log' => [],
    ],
];

$completedBuild = null;

foreach ($builds as $spec) {
    $attributes = [
        'team_id' => $team->id,
        'app_identifier_id' => $spec['identifier']->id,
        'requested_by_user_id' => $user?->id,
        'profile_type' => $spec['profile'],
        'status' => $spec['status'],
        'reporting_mode' => $spec['mode'],
        'context_snapshot' => ['runner' => 'github-actions', 'commit' => 'a1b2c3d'],
        'tags' => $spec['tags'],
    ];

    if ($spec['log'] !== []) {
        $attributes['log_output'] = implode("\n", $spec['log']);
    }
    if ($spec['started'] !== null) {
        $attributes['started_at'] = now()->subMinutes($spec['started']);
    }
    if ($spec['finished'] !== null) {
        $attributes['finished_at'] = now()->subMinutes($spec['finished']);
    }
    if ($spec['mode'] === BuildReportingMode::Eas) {
        $attributes['expo_build_id'] = '22222222-3333-4444-5555-666666666666';
    }

    $build = $app->buildRecords()->firstOrCreate(['request_uuid' => $spec['uuid']], $attributes);

    if ($spec['status'] === BuildStatus::Completed) {
        $completedBuild = $build;
    }
}
$out('builds: '.$app->buildRecords()->count());

// --- artifacts on the completed build --------------------------------------

if ($completedBuild) {
    foreach ([
        ['name' => 'app-release.aab', 'metadata' => ['channel' => 'internal', 'size_label' => '28.4 MB']],
        ['name' => 'mapping.txt', 'metadata' => ['channel' => 'internal']],
    ] as $spec) {
        $artifact = Artifact::firstOrCreate(
            ['build_record_id' => $completedBuild->id, 'name' => $spec['name']],
            ['team_id' => $team->id, 'metadata' => $spec['metadata']],
        );

        if ($artifact->getFirstMedia('file') === null) {
            $path = tempnam(sys_get_temp_dir(), 'artifact');
            file_put_contents($path, "documentation placeholder for {$spec['name']}\n");
            $artifact->addMedia($path)->usingFileName($spec['name'])->toMediaCollection('file');
        }
    }
    $out('artifacts: '.$completedBuild->artifacts()->count());
}

// --- media library files ---------------------------------------------------

$files = app(AppFileLibrary::class);

foreach ([
    ['name' => 'release-checklist.md', 'body' => "# Release checklist\n\n- [ ] Regenerate screenshots\n- [ ] Update the changelog\n", 'metadata' => ['owner' => 'release-team', 'channel' => 'internal']],
    ['name' => 'mapping-1.4.0.txt', 'body' => "com.acme.customer.PaymentSheet -> a.b.c\n", 'metadata' => ['build' => '1.4.0']],
] as $spec) {
    if ($files->findByName($app, $spec['name'])) {
        continue;
    }

    $path = tempnam(sys_get_temp_dir(), 'appfile');
    file_put_contents($path, $spec['body']);
    $app->addMedia($path)
        ->usingFileName($spec['name'])
        ->withCustomProperties(['metadata' => $spec['metadata']])
        ->toMediaCollection(AppFileLibrary::COLLECTION);
}
$out('files: '.$files->query($app)->count());

// --- icon and splash screen ------------------------------------------------

$makePng = static function (int $width, int $height, array $rgb): string {
    $image = imagecreatetruecolor($width, $height);
    imagefill($image, 0, 0, imagecolorallocate($image, ...$rgb));
    $path = tempnam(sys_get_temp_dir(), 'asset').'.png';
    imagepng($image, $path);
    imagedestroy($image);

    return $path;
};

if (! $app->getFirstMedia('app_icon')) {
    $app->addMedia($makePng(1024, 1024, [59, 130, 246]))
        ->usingFileName('icon.png')
        ->toMediaCollection('app_icon');
}
if (! $app->getFirstMedia('splash_icon')) {
    $app->addMedia($makePng(1242, 2688, [17, 24, 39]))
        ->usingFileName('splash.png')
        ->toMediaCollection('splash_icon');
}
// Query the relation rather than getFirstMedia(), whose result was cached before
// the uploads above and would report "no" for media that was just attached.
$assetCount = static fn (string $collection): int => $app->media()
    ->where('collection_name', $collection)
    ->count();
$out('assets: icon='.$assetCount('app_icon').' splash='.$assetCount('splash_icon'));

// --- integrations, a pipeline, and a webhook endpoint ----------------------
//
// These exist so "integrations list", "pipelines list", and "webhooks list" have
// rows to print. Provider-backed reads (integrations repos/workflows/refs, and
// every eas action) still cannot be captured: they call GitHub and Expo, which
// needs real credentials this data deliberately does not carry.

$ciIntegration = Integration::firstOrCreate(
    [
        'team_id' => $team->id,
        'kind' => IntegrationKind::CiCd,
        'provider' => IntegrationProvider::GitHub,
        'name' => 'acme/customer-app',
    ],
    [
        'status' => IntegrationStatus::Active,
        'created_by_user_id' => $user->id,
        'updated_by_user_id' => $user->id,
    ],
);

CiCdPipeline::firstOrCreate(
    ['integration_id' => $ciIntegration->id, 'name' => 'Android release'],
    [
        'team_id' => $team->id,
        'mobile_app_id' => $app->id,
        'app_identifier_id' => $android->id,
        'profile_type' => ProfileType::Production,
        'enabled' => true,
        'repository_id' => 'R_kgDOJdocs1',
        'repository_full_name' => 'acme/customer-app',
        'workflow_id' => '98765432',
        'workflow_name' => 'Release',
        'workflow_path' => '.github/workflows/release.yml',
        'default_ref' => 'main',
        'created_by_user_id' => $user->id,
        'updated_by_user_id' => $user->id,
    ],
);
$out('pipelines: '.CiCdPipeline::where('team_id', $team->id)->count());

$webhookIntegration = Integration::firstOrCreate(
    [
        'team_id' => $team->id,
        'kind' => IntegrationKind::Webhook,
        'provider' => IntegrationProvider::Http,
        'name' => 'Release notifier',
    ],
    [
        'status' => IntegrationStatus::Active,
        'created_by_user_id' => $user->id,
        'updated_by_user_id' => $user->id,
    ],
);

WebhookEndpoint::firstOrCreate(
    ['integration_id' => $webhookIntegration->id],
    [
        'team_id' => $team->id,
        'url' => 'https://hooks.acme.example/app-manager',
        'secret' => 'whsec_docscapture0000000000',
        // secret_hint is varchar(8); keep the ellipsis form inside that budget.
        'secret_hint' => 'whse…000',
        'subscribed_events' => ['build.created', 'build.completed', 'artifact.created'],
    ],
);
$webhookEndpoint = WebhookEndpoint::where('team_id', $team->id)->firstOrFail();
$out('webhooks: '.WebhookEndpoint::where('team_id', $team->id)->count());

// ---------------------------------------------------------------------------
// Everything below exists so the web UI screenshots have something to show. A
// screen documented against an empty table teaches nothing, and the seeded team
// starts with one app, one member, and no history at all.
// ---------------------------------------------------------------------------

// --- more applications, so the catalog has rows ----------------------------

$extraApps = [
    [
        'slug' => 'delivery-driver',
        'name' => 'Delivery Driver',
        'description' => 'Courier companion app.',
        'identifiers' => [
            [Platform::Android, 'com.acme.driver', 'Delivery Driver (Android)', '2.1.0', 2100],
            [Platform::Ios, 'com.acme.driver', 'Delivery Driver (iOS)', '2.1.0', null],
        ],
    ],
    [
        'slug' => 'store-kiosk',
        'name' => 'Store Kiosk',
        'description' => 'In-store self-service terminal.',
        'identifiers' => [
            [Platform::Android, 'com.acme.kiosk', 'Store Kiosk', '0.9.3', 93],
        ],
    ],
    [
        'slug' => 'partner-portal',
        'name' => 'Partner Portal',
        'description' => 'Web portal for partners.',
        'identifiers' => [
            [Platform::Web, 'partners.acme.example', 'Partner Portal', '3.0.0', null],
        ],
    ],
];

foreach ($extraApps as $spec) {
    $extra = MobileApp::firstOrCreate(
        ['team_id' => $team->id, 'slug' => $spec['slug']],
        ['name' => $spec['name'], 'description' => $spec['description']],
    );

    foreach ($spec['identifiers'] as [$platform, $identifier, $name, $version, $versionCode]) {
        $attributes = ['name' => $name, 'version' => $version];
        if ($versionCode !== null) {
            $attributes['version_code'] = $versionCode;
        }
        $extra->appIdentifiers()->firstOrCreate(
            ['platform' => $platform, 'identifier' => $identifier],
            $attributes,
        );
    }
}
$out('apps: '.MobileApp::where('team_id', $team->id)->count());

// --- a second app with build history --------------------------------------

$driver = MobileApp::where('team_id', $team->id)->where('slug', 'delivery-driver')->firstOrFail();
$driverAndroid = $driver->appIdentifiers()->where('platform', Platform::Android)->firstOrFail();

foreach ([
    ['20000000-0000-4000-8000-000000000001', BuildStatus::Completed, [['android', '#3ddc84'], ['2.1.0', '#f59e0b']], 2880, 2874],
    ['20000000-0000-4000-8000-000000000002', BuildStatus::Completed, [['android', '#3ddc84'], ['hotfix', '#ef4444']], 600, 596],
    ['20000000-0000-4000-8000-000000000003', BuildStatus::Cancelled, [['android', '#3ddc84']], 120, 118],
] as [$uuid, $status, $tags, $started, $finished]) {
    $driver->buildRecords()->firstOrCreate(
        ['request_uuid' => $uuid],
        [
            'team_id' => $team->id,
            'app_identifier_id' => $driverAndroid->id,
            'requested_by_user_id' => $user->id,
            'profile_type' => ProfileType::Production,
            'status' => $status,
            'reporting_mode' => BuildReportingMode::Automatic,
            'context_snapshot' => ['runner' => 'github-actions', 'commit' => 'e5f6a7b'],
            'tags' => $tags,
            'log_output' => "[1/3] Restoring dependencies...\n[2/3] Compiling sources...\n[3/3] Done.",
            'started_at' => now()->subMinutes($started),
            'finished_at' => now()->subMinutes($finished),
        ],
    );
}
$out('builds: '.BuildRecord::where('team_id', $team->id)->count());

// --- team members and a pending invitation --------------------------------

foreach ([
    ['maria@acme.example', 'Maria Lopez', TeamRole::Admin],
    ['sam@acme.example', 'Sam Okafor', TeamRole::Contributor],
    ['ravi@acme.example', 'Ravi Patel', TeamRole::Viewer],
] as [$email, $name, $role]) {
    $member = User::firstOrCreate(
        ['email' => $email],
        ['name' => $name, 'password' => 'DocsCapture123!'],
    );

    if (! $team->users()->whereKey($member->id)->exists()) {
        $team->users()->attach($member->id, ['role' => $role->value]);
    }
}
$out('members: '.$team->users()->count());

TeamInvitation::firstOrCreate(
    ['team_id' => $team->id, 'email' => 'nina@acme.example'],
    [
        'invited_by' => $user->id,
        'role' => TeamRole::Contributor->value,
        'token' => hash('sha256', 'docs-capture-invitation'),
        'expires_at' => now()->addDays(6),
    ],
);
$out('invitations: '.TeamInvitation::where('team_id', $team->id)->whereNull('accepted_at')->count());

// --- API keys with visibly different scope sets ---------------------------
//
// Tokens are stored hashed, so these are unusable as credentials. The point is
// that the API keys screen shows more than one row, with scopes that differ.

foreach ([
    ['CI build reporter', ['builds:read', 'builds:write', 'logs:write', 'artifacts:write']],
    ['Release monitor (read-only)', ['apps:read', 'builds:read', 'webhooks:read', 'deliveries:read']],
    ['Fastlane runner', ['builds:read', 'builds:write', 'credentials:read', 'credentials:reveal', 'logs:write', 'artifacts:write']],
] as [$name, $abilities]) {
    $user->tokens()->updateOrCreate(
        ['name' => $name],
        [
            'team_id' => $team->id,
            'token' => hash('sha256', 'docs-capture-'.$name),
            'abilities' => $abilities,
            'last_used_at' => now()->subHours(random_int(2, 40)),
        ],
    );
}
$out('api keys: '.$team->apiKeys()->count());

// --- notification destinations --------------------------------------------

foreach ([
    ['Slack', IntegrationProvider::Slack, '#mobile-releases', ['webhook_url' => 'https://hooks.slack.example/services/T000/B000/docs']],
    ['Discord', IntegrationProvider::Discord, '#builds', ['webhook_url' => 'https://discord.example/api/webhooks/000/docs']],
] as [$label, $provider, $destination, $credentials]) {
    $notificationIntegration = Integration::firstOrCreate(
        [
            'team_id' => $team->id,
            'kind' => IntegrationKind::Notification,
            'provider' => $provider,
            'name' => $label.' notifications',
        ],
        [
            'status' => IntegrationStatus::Active,
            'created_by_user_id' => $user->id,
            'updated_by_user_id' => $user->id,
        ],
    );

    NotificationDestination::firstOrCreate(
        ['integration_id' => $notificationIntegration->id],
        [
            'team_id' => $team->id,
            'destination' => $destination,
            'credentials' => $credentials,
            'subscribed_events' => ['build.created', 'build.completed'],
            // templates is not-null: one message body per subscribed event.
            // Note the event catalog has no "build.failed": a finished build reports
            // through build.completed and carries its own status.
            // Placeholders are double-braced and dotted; see NotificationTemplateRenderer.
            'templates' => [
                'build.created' => '🚀 {{app.name}} {{build.profile}} build queued by {{build.requested_by}}',
                'build.completed' => '{{build.status_icon}} {{app.name}} {{build.profile}} build {{build.status}} in {{build.duration}} — {{build.url}}',
            ],
            'last_delivered_at' => now()->subMinutes(35),
        ],
    );
}
$out('notification destinations: '.NotificationDestination::where('team_id', $team->id)->count());

// --- webhook deliveries, including a failure ------------------------------
//
// The delivery view is only worth documenting when it has a failed attempt to
// show: that is the screen someone opens when a receiver is broken.

foreach ([
    ['build.completed', WebhookDeliveryStatus::Delivered, 200, 1, 142, null, 30],
    ['artifact.created', WebhookDeliveryStatus::Delivered, 200, 1, 96, null, 28],
    ['build.created', WebhookDeliveryStatus::Exhausted, 500, 5, 2041, 'HTTP 500: upstream receiver returned an error page', 15],
] as [$eventType, $status, $responseStatus, $attempts, $duration, $error, $minutesAgo]) {
    $event = WebhookEvent::firstOrCreate(
        [
            'team_id' => $team->id,
            'event_type' => $eventType,
            'aggregate_type' => 'build',
            'aggregate_id' => $completedBuild?->id ?? $app->id,
        ],
        [
            'schema_version' => 1,
            'payload' => ['data' => ['id' => $completedBuild?->id, 'status' => 'completed']],
            'occurred_at' => now()->subMinutes($minutesAgo + 1),
        ],
    );

    WebhookDelivery::firstOrCreate(
        ['webhook_event_id' => $event->id, 'webhook_endpoint_id' => $webhookEndpoint->id],
        [
            'team_id' => $team->id,
            'status' => $status,
            'attempt_count' => $attempts,
            'payload_snapshot' => $event->payload,
            'response_status' => $responseStatus,
            'duration_ms' => $duration,
            'last_error' => $error,
            'signature_version' => 'v1',
            'delivered_at' => $status === WebhookDeliveryStatus::Delivered
                ? now()->subMinutes($minutesAgo)
                : null,
        ],
    );
}
$out('webhook deliveries: '.WebhookDelivery::where('team_id', $team->id)->count());

// --- audit log entries ----------------------------------------------------
//
// Audits are written by the auditing package, which resolves the actor from
// ActorContextManager. Running the mutations as the docs user therefore produces
// properly attributed entries instead of anonymous "system" rows.

$actorContext = app(ActorContextManager::class);

$actorContext->runAs(
    ActorContext::forUser($user, 'web', null, '203.0.113.24', 'Mozilla/5.0 (Macintosh) Docs/1.0'),
    function () use ($app, $driver, $android): void {
        $app->update(['description' => 'Consumer-facing mobile client.']);
        $driver->update(['description' => 'Courier companion app.']);
        $android->update(['version' => '1.4.0']);
    },
);
$out('audit entries: '.\App\Models\Audit::count());

$out('done');

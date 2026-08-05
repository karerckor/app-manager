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
use App\Enums\SentryEnvelopeStatus;
use App\Enums\SentryEventSource;
use App\Enums\SentryRoutingMethod;
use App\Enums\TeamRole;
use App\Enums\WebhookDeliveryStatus;
use App\Models\Artifact;
use App\Models\BuildRecord;
use App\Models\CiCdPipeline;
use App\Models\Credential;
use App\Models\Integration;
use App\Models\MobileApp;
use App\Models\NotificationDestination;
use App\Models\SentryDsn;
use App\Models\SentryDsnRoute;
use App\Models\SentryEnvelope;
use App\Models\SentryEvent;
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

// A credential that references an external vault (AWS Secrets Manager) instead
// of storing the file locally. This exists so the "Reference my vault" mode has
// something to show in the documentation screenshots.
$externalCredential = Credential::firstOrCreate(
    [
        'team_id' => $team->id,
        'type' => CredentialType::IosCertificate,
        'name' => 'CI Distribution Certificate',
    ],
    [
        'storage_mode' => \App\Enums\CredentialStorageMode::External,
        'payload' => ['team_id' => 'ABCDEF1234'],
    ],
);
$iosProfile->credentials()->syncWithoutDetaching([$externalCredential->id]);

\App\Models\CredentialVaultReference::firstOrCreate(
    ['credential_id' => $externalCredential->id],
    [
        'team_id' => $team->id,
        'provider' => \App\Enums\VaultProvider::AwsSecretsManager,
        'resource' => 'arn:aws:secretsmanager:eu-central-1:123456789012:secret/mobile/ios-dist-cert',
        'metadata' => ['region' => 'eu-central-1', 'version_id' => 'docs-capture'],
    ],
);

// Expired certificate — builds using this fail now.
$expiredCert = Credential::firstOrCreate(
    [
        'team_id' => $team->id,
        'type' => CredentialType::IosCertificate,
        'name' => 'Expired Distribution Certificate',
    ],
    [
        'payload' => ['password' => 'docs-demo'],
        'expires_at' => now()->subDays(10),
    ],
);

// Credential approaching expiry — the "Expiring" filter finds these.
$expiringKeystore = Credential::firstOrCreate(
    [
        'team_id' => $team->id,
        'type' => CredentialType::AndroidKeystore,
        'name' => 'Staging Keystore',
    ],
    [
        'payload' => ['store_password' => 'docs-demo', 'key_alias' => 'staging', 'key_password' => 'docs-demo'],
        'expires_at' => now()->addDays(5),
    ],
);

// Google Play service account — external vault, different provider.
$googleServiceAccount = Credential::firstOrCreate(
    [
        'team_id' => $team->id,
        'type' => CredentialType::GooglePlayServiceAccount,
        'name' => 'Play Console Service Account',
    ],
    [
        'storage_mode' => \App\Enums\CredentialStorageMode::External,
        'payload' => ['client_email' => 'play-deploy@docs-capture.iam.gserviceaccount.com'],
    ],
);
$androidProfile->credentials()->syncWithoutDetaching([$googleServiceAccount->id]);

\App\Models\CredentialVaultReference::firstOrCreate(
    ['credential_id' => $googleServiceAccount->id],
    [
        'team_id' => $team->id,
        'provider' => \App\Enums\VaultProvider::HashicorpVault,
        'resource' => 'secret/data/mobile/play-service-account',
        'metadata' => ['namespace' => 'docs-capture', 'engine' => 'kv-v2'],
        'verification_status' => \App\Enums\CredentialVerificationStatus::Verified,
        'verified_at' => now()->subHours(6),
    ],
);

// Provisioning profile — attached, local, with file info visible.
$provisioningProfile = Credential::firstOrCreate(
    [
        'team_id' => $team->id,
        'type' => CredentialType::ProvisioningProfile,
        'name' => 'App Store Provisioning Profile',
    ],
    [
        'payload' => ['team_id' => 'ABCDEF1234', 'bundle_id' => 'com.acme.customer'],
        'expires_at' => now()->addDays(180),
    ],
);
$iosProfile->credentials()->syncWithoutDetaching([$provisioningProfile->id]);

// Unattached credential — not linked to any profile, found by the "Unattached" filter.
Credential::firstOrCreate(
    [
        'team_id' => $team->id,
        'type' => CredentialType::ApnsKey,
        'name' => 'Unused APNs Key',
    ],
    [
        'payload' => ['key_id' => 'ABC123DEFG', 'team_id' => 'ABCDEF1234', 'issuer_id' => '1234567890'],
        'expires_at' => now()->addDays(365),
    ],
);

$out("credentials: {$keystore->id} {$certificate->id} {$externalCredential->id}");

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
$createdBuilds = [];

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

    $createdBuilds[$spec['uuid']] = $build->id;
}
$out('builds: '.$app->buildRecords()->count());

// Write build IDs so the screenshot capture script can navigate to detail pages.
$buildIdsPath = dirname(__DIR__, 2).'/apps/docs/src/data/build-ids.json';
@mkdir(dirname($buildIdsPath), 0755, true);
file_put_contents($buildIdsPath, json_encode($createdBuilds, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
$out("build ids: {$buildIdsPath}");

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

// --- Sentry relay and resolved telemetry ----------------------------------
//
// This is deliberately stored locally instead of being sent to a real upstream:
// documentation screenshots must be deterministic and must never publish a
// customer's telemetry. The event payload still has the complete Sentry shape
// used by the inspector, including a source context, request, user and tags.

$sentryIntegration = Integration::firstOrCreate(
    [
        'team_id' => $team->id,
        'kind' => IntegrationKind::Observability,
        'provider' => IntegrationProvider::Sentry,
        'name' => 'Customer telemetry relay',
    ],
    [
        'status' => IntegrationStatus::Active,
        'created_by_user_id' => $user->id,
        'updated_by_user_id' => $user->id,
    ],
);

$sentryDsn = SentryDsn::firstOrCreate(
    ['integration_id' => $sentryIntegration->id],
    [
        'team_id' => $team->id,
        'public_key' => 'docscapturetelemetry000000000001',
        'project_id' => '2656211',
        'routing_method' => SentryRoutingMethod::Event,
        'event_source' => SentryEventSource::Contexts,
        'app_key' => 'app.slug',
        'identifier_key' => 'app.identifier',
        'upstream_dsn' => 'https://public-docs-key@example.invalid/2656211',
        'last_event_at' => now()->subMinutes(2),
        'last_forwarded_at' => now()->subMinutes(2),
    ],
);

$sentryIntegration->update(['external_id' => $sentryDsn->id]);

foreach ([
    [$ios, 'docscaptureiosroute0000000000001'],
    [$web, 'docscapturewebroute0000000000001'],
] as [$identifier, $publicKey]) {
    SentryDsnRoute::firstOrCreate(
        ['sentry_dsn_id' => $sentryDsn->id, 'app_identifier_id' => $identifier->id],
        ['mobile_app_id' => $app->id, 'public_key' => $publicKey],
    );
}

$sentryTraceId = 'd0c5c6f7a18d4c2fb0bfa4b8c4f0a711';
$errorEnvelope = SentryEnvelope::updateOrCreate(
    ['sentry_dsn_id' => $sentryDsn->id, 'body_base64' => base64_encode('{"event_id":"docserror00000000000000000000001"}')],
    [
        'team_id' => $team->id,
        'mobile_app_id' => $app->id,
        'app_identifier_id' => $web->id,
        'header' => ['event_id' => 'docserror00000000000000000000001'],
        'status' => SentryEnvelopeStatus::Forwarded,
        'forward_attempts' => 1,
        'upstream_response_status' => 200,
        'forwarded_at' => now()->subMinute(),
    ],
);

SentryEvent::updateOrCreate(
    ['sentry_dsn_id' => $sentryDsn->id, 'event_id' => 'docserror00000000000000000000001'],
    [
        'team_id' => $team->id,
        'sentry_envelope_id' => $errorEnvelope->id,
        'mobile_app_id' => $app->id,
        'app_identifier_id' => $web->id,
        'item_type' => 'event',
        'level' => 'error',
        'title' => 'Payment authorization failed',
        'release' => 'customer-web@1.4.0',
        'environment' => 'production',
        'platform' => 'javascript',
        'trace_id' => $sentryTraceId,
        'occurred_at' => now()->subMinute(),
        'payload' => [
            'exception' => ['values' => [[
                'type' => 'PaymentAuthorizationError',
                'value' => 'The card authorization expired before checkout completed.',
                'mechanism' => ['handled' => true, 'type' => 'generic'],
                'stacktrace' => ['frames' => [[
                    'filename' => '/srv/customer-web/src/checkout/authorizePayment.ts',
                    'function' => 'authorizePayment',
                    'lineno' => 42,
                    'colno' => 15,
                    'in_app' => true,
                    'pre_context' => ['const authorization = await gateway.authorize(cart);', 'if (authorization.expired) {'],
                    'context_line' => '  throw new PaymentAuthorizationError(authorization.reason);',
                    'post_context' => ['}', 'return authorization;'],
                ]]],
            ]]],
            'contexts' => [
                'app' => ['slug' => 'customer-app', 'identifier' => 'customer.acme.example', 'version' => '1.4.0'],
                'trace' => ['trace_id' => $sentryTraceId, 'op' => 'http.server', 'status' => 'internal_error'],
                'runtime' => ['name' => 'node', 'version' => '22.22.3'],
            ],
            'request' => [
                'method' => 'POST',
                'url' => 'https://customer.acme.example/api/checkout',
                'query_string' => 'currency=USD',
                'headers' => [['Content-Type', 'application/json']],
                'data' => ['cart_id' => 'cart_42', 'payment_method' => 'card'],
            ],
            'user' => ['id' => 'customer_42', 'email' => 'ava@example.test', 'segment' => 'gold'],
            'tags' => ['feature' => 'checkout', 'payment_provider' => 'acme-pay'],
            'extra' => ['cart_total' => 149.99, 'order_id' => 'order_42'],
            'sdk' => ['name' => 'sentry.javascript.node', 'version' => '9.47.1'],
        ],
    ],
);

$repeatEnvelope = SentryEnvelope::updateOrCreate(
    ['sentry_dsn_id' => $sentryDsn->id, 'body_base64' => base64_encode('{"event_id":"docsrepeat0000000000000000000001"}')],
    [
        'team_id' => $team->id,
        'mobile_app_id' => $app->id,
        'app_identifier_id' => $web->id,
        'header' => ['event_id' => 'docsrepeat0000000000000000000001'],
        'status' => SentryEnvelopeStatus::Forwarded,
        'forward_attempts' => 1,
        'upstream_response_status' => 200,
        'forwarded_at' => now()->subMinutes(2),
    ],
);

SentryEvent::updateOrCreate(
    ['sentry_dsn_id' => $sentryDsn->id, 'event_id' => 'docsrepeat0000000000000000000001'],
    [
        'team_id' => $team->id,
        'sentry_envelope_id' => $repeatEnvelope->id,
        'mobile_app_id' => $app->id,
        'app_identifier_id' => $web->id,
        'item_type' => 'event',
        'level' => 'error',
        'title' => 'Payment authorization failed',
        'release' => 'customer-web@1.4.0',
        'environment' => 'production',
        'platform' => 'javascript',
        'trace_id' => $sentryTraceId,
        'occurred_at' => now()->subMinutes(2),
        'payload' => [
            'exception' => ['values' => [[
                'type' => 'PaymentAuthorizationError',
                'value' => 'The card authorization expired before checkout completed.',
                'stacktrace' => ['frames' => [[
                    'filename' => '/srv/customer-web/src/checkout/authorizePayment.ts',
                    'function' => 'authorizePayment',
                    'in_app' => true,
                ]]],
            ]]],
            'contexts' => ['trace' => ['trace_id' => $sentryTraceId, 'op' => 'http.server', 'status' => 'internal_error']],
        ],
    ],
);

$traceEnvelope = SentryEnvelope::updateOrCreate(
    ['sentry_dsn_id' => $sentryDsn->id, 'body_base64' => base64_encode('{"event_id":"docstrace00000000000000000000001"}')],
    [
        'team_id' => $team->id,
        'mobile_app_id' => $app->id,
        'app_identifier_id' => $ios->id,
        'header' => ['event_id' => 'docstrace00000000000000000000001'],
        'status' => SentryEnvelopeStatus::Forwarded,
        'forward_attempts' => 1,
        'upstream_response_status' => 200,
        'forwarded_at' => now()->subMinutes(3),
    ],
);

SentryEvent::updateOrCreate(
    ['sentry_dsn_id' => $sentryDsn->id, 'event_id' => 'docstrace00000000000000000000001'],
    [
        'team_id' => $team->id,
        'sentry_envelope_id' => $traceEnvelope->id,
        'mobile_app_id' => $app->id,
        'app_identifier_id' => $ios->id,
        'item_type' => 'transaction',
        'level' => 'info',
        'title' => 'POST /api/checkout',
        'transaction' => 'POST /api/checkout',
        'release' => 'customer-ios@1.4.0',
        'environment' => 'production',
        'platform' => 'ios',
        'trace_id' => $sentryTraceId,
        'occurred_at' => now()->subMinutes(3),
        'payload' => [
            'start_timestamp' => 100.25,
            'timestamp' => 101.5,
            'contexts' => ['trace' => ['trace_id' => $sentryTraceId, 'op' => 'http.client', 'status' => 'internal_error']],
            'spans' => [['span_id' => 'd0c5c6f7a18d4c2f']],
        ],
    ],
);
$out('sentry: '.SentryDsn::where('team_id', $team->id)->count().' DSN, '.SentryEvent::where('team_id', $team->id)->count().' events');

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

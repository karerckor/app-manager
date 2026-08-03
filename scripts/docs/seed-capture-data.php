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
use App\Enums\Platform;
use App\Enums\ProfileType;
use App\Models\Artifact;
use App\Models\Credential;
use App\Models\MobileApp;
use App\Models\Team;
use App\Models\User;
use App\Support\Apps\AppFileLibrary;

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

$out('done');

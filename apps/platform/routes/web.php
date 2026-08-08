<?php

use App\Http\Controllers\AppAssetController;
use App\Http\Controllers\AppBuildPageController;
use App\Http\Controllers\AppFileController;
use App\Http\Controllers\AppIdentifierController;
use App\Http\Controllers\AppIdentifierPageController;
use App\Http\Controllers\AppMediaLibraryController;
use App\Http\Controllers\AppOverviewController;
use App\Http\Controllers\AppTelemetryDetailPageController;
use App\Http\Controllers\AppTelemetryPageController;
use App\Http\Controllers\AuditLogController;
use App\Http\Controllers\BlueprintController;
use App\Http\Controllers\BlueprintHookController;
use App\Http\Controllers\BuildArtifactController;
use App\Http\Controllers\BuildLogArchiveController;
use App\Http\Controllers\BuildLogDownloadController;
use App\Http\Controllers\CredentialController;
use App\Http\Controllers\CredentialVaultController;
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\ExpoAppController;
use App\Http\Controllers\ExpoBuildController;
use App\Http\Controllers\ExpoCredentialController;
use App\Http\Controllers\ExpoProjectController;
use App\Http\Controllers\ExpoSubmissionController;
use App\Http\Controllers\ForceBuildStatusController;
use App\Http\Controllers\InstallerController;
use App\Http\Controllers\Integrations\CiCdDiscoveryController;
use App\Http\Controllers\Integrations\CiCdPipelineController;
use App\Http\Controllers\Integrations\ExpoConnectionController;
use App\Http\Controllers\Integrations\GitHubConnectionController;
use App\Http\Controllers\Integrations\GitLabConnectionController;
use App\Http\Controllers\Integrations\IntegrationIndexController;
use App\Http\Controllers\Integrations\NotificationDestinationController;
use App\Http\Controllers\Integrations\PipelineBuildController;
use App\Http\Controllers\Integrations\SentryDsnController;
use App\Http\Controllers\Integrations\WebhookDeliveryController;
use App\Http\Controllers\Integrations\WebhookEndpointController;
use App\Http\Controllers\ManualBuildController;
use App\Http\Controllers\MobileAppController;
use App\Http\Controllers\ProfileController;
use App\Http\Controllers\PushNotificationToolController;
use App\Http\Controllers\SentryArchiveController;
use App\Http\Controllers\TagsController;
use App\Http\Controllers\TeamApiKeyController;
use App\Http\Controllers\TeamBuildPageController;
use App\Http\Controllers\TeamController;
use App\Http\Controllers\TeamInvitationController;
use App\Http\Controllers\TeamMemberController;
use App\Http\Controllers\Webhooks\GitHubWebhookController;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

Route::get('/install', [InstallerController::class, 'index'])->name('installer.index');
Route::post('/install', [InstallerController::class, 'store'])
    ->middleware('throttle:5,1')
    ->name('installer.store');

Route::get('/', function () {
    return auth()->check() ? redirect()->route('dashboard') : redirect()->route('login');
});

Route::post('/webhooks/github', GitHubWebhookController::class)
    ->name('webhooks.github');

Route::middleware('auth')->group(function () {
    Route::get('/profile', [ProfileController::class, 'show'])->name('profile.show');
    Route::post('/profile/recovery-codes', [ProfileController::class, 'recoveryCodes'])
        ->middleware('throttle:6,1')
        ->name('profile.recovery-codes');
    Route::delete('/profile', [ProfileController::class, 'destroy'])->name('profile.destroy');

    Route::get('/team-invitations/{invitation:token}', [TeamInvitationController::class, 'show'])
        ->name('team-invitations.show');
    Route::post('/team-invitations/{invitation:token}/accept', [TeamInvitationController::class, 'accept'])
        ->name('team-invitations.accept');

    Route::get('/dashboard', function (Request $request) {
        $user = $request->user();
        $team = $user->currentTeam;

        if (! $team || ! $user->teams()->whereKey($team->id)->exists()) {
            $team = $user->teams()->first();
        }

        if (! $team) {
            return redirect()->route('accounts.create');
        }

        if ($user->current_team_id !== $team->id) {
            $user->update(['current_team_id' => $team->id]);
        }

        return redirect()->route('accounts.apps.index', ['team' => $team]);
    })->name('dashboard');

    Route::get('/accounts/create', [TeamController::class, 'create'])->name('accounts.create');
    Route::post('/accounts', [TeamController::class, 'store'])->name('accounts.store');
    Route::get('/integrations/github/callback', [GitHubConnectionController::class, 'callback'])
        ->name('integrations.github.callback');
    Route::get('/integrations/gitlab/callback', [GitLabConnectionController::class, 'callback'])
        ->name('integrations.gitlab.callback');

    Route::prefix('/accounts/{team:slug}')
        ->middleware('team.member')
        ->scopeBindings()->name('accounts.')->group(function () {
            Route::get('/team', [TeamController::class, 'index'])->name('team.index');
            Route::patch('/team', [TeamController::class, 'update'])->name('team.update');
            Route::post('/team/invitations', [TeamInvitationController::class, 'store'])->name('team.invitations.store');
            Route::delete('/team/invitations/{invitation}', [TeamInvitationController::class, 'destroy'])->name('team.invitations.destroy');
            Route::post('/team/members', [TeamMemberController::class, 'store'])
                ->middleware('team.permission:manage_team')
                ->name('team.members.store');
            Route::patch('/team/members/{user}', [TeamMemberController::class, 'update'])
                ->middleware('team.permission:manage_team')
                ->name('team.members.update');
            Route::delete('/team/members/{user}', [TeamMemberController::class, 'destroy'])
                ->middleware('team.permission:manage_team')
                ->name('team.members.destroy');
            Route::get('/integrations', [IntegrationIndexController::class, 'ciCd'])
                ->name('integrations.index');
            Route::get('/integrations/cicd/{integration}', [IntegrationIndexController::class, 'showCiCd'])
                ->name('integrations.cicd.show');
            Route::get('/integrations/eas', [IntegrationIndexController::class, 'eas'])
                ->name('integrations.eas.index');
            Route::get('/integrations/webhooks', [IntegrationIndexController::class, 'webhooks'])
                ->name('integrations.webhooks.index');
            Route::get('/integrations/notifications', [IntegrationIndexController::class, 'notifications'])
                ->name('integrations.notifications.index');
            Route::get('/integrations/sentry', [IntegrationIndexController::class, 'sentry'])
                ->name('integrations.sentry.index');
            Route::get('/integrations/github/connect', [GitHubConnectionController::class, 'connect'])
                ->name('integrations.github.connect');
            Route::post('/integrations/{integration}/github/sync', [GitHubConnectionController::class, 'sync'])
                ->name('integrations.github.sync');
            Route::delete('/integrations/{integration}/github', [GitHubConnectionController::class, 'disconnect'])
                ->name('integrations.github.disconnect');
            Route::get('/integrations/gitlab/connect', [GitLabConnectionController::class, 'connect'])
                ->name('integrations.gitlab.connect');
            Route::post('/integrations/gitlab/self-hosted', [GitLabConnectionController::class, 'storeSelfHosted'])
                ->name('integrations.gitlab.self-hosted.store');
            Route::post('/integrations/{integration}/gitlab/sync', [GitLabConnectionController::class, 'sync'])
                ->name('integrations.gitlab.sync');
            Route::delete('/integrations/{integration}/gitlab', [GitLabConnectionController::class, 'disconnect'])
                ->name('integrations.gitlab.disconnect');
            Route::post('/integrations/expo', [ExpoConnectionController::class, 'store'])
                ->middleware('throttle:5,1')
                ->name('integrations.expo.store');
            Route::post('/integrations/{integration}/expo/sync', [ExpoConnectionController::class, 'sync'])
                ->name('integrations.expo.sync');
            Route::delete('/integrations/{integration}/expo', [ExpoConnectionController::class, 'disconnect'])
                ->name('integrations.expo.disconnect');
            Route::post('/integrations/{integration}/expo/projects/{expoProject}/import', [ExpoProjectController::class, 'import'])
                ->name('integrations.expo.projects.import');
            Route::post('/integrations/{integration}/expo/projects/import-batch', [ExpoProjectController::class, 'importBatch'])
                ->name('integrations.expo.projects.import-batch');
            Route::get('/integrations/{integration}/repositories', [CiCdDiscoveryController::class, 'repositories'])
                ->name('integrations.repositories.index');
            Route::get('/integrations/{integration}/workflows', [CiCdDiscoveryController::class, 'workflows'])
                ->name('integrations.workflows.index');
            Route::get('/integrations/{integration}/refs', [CiCdDiscoveryController::class, 'refs'])
                ->name('integrations.refs.index');
            Route::post('/integrations/{integration}/pipelines', [CiCdPipelineController::class, 'store'])
                ->name('integrations.pipelines.store');
            Route::patch('/integrations/{integration}/pipelines/batch/name', [CiCdPipelineController::class, 'renameBatch'])
                ->name('integrations.pipelines.batch.rename');
            Route::delete('/integrations/{integration}/pipelines/batch', [CiCdPipelineController::class, 'destroyBatch'])
                ->name('integrations.pipelines.batch.destroy');
            Route::patch('/integrations/{integration}/pipelines/{pipeline}', [CiCdPipelineController::class, 'update'])
                ->name('integrations.pipelines.update');
            Route::patch('/integrations/{integration}/pipelines/{pipeline}/name', [CiCdPipelineController::class, 'rename'])
                ->name('integrations.pipelines.rename');
            Route::delete('/integrations/{integration}/pipelines/{pipeline}', [CiCdPipelineController::class, 'destroy'])
                ->name('integrations.pipelines.destroy');
            Route::post('/integrations/webhooks', [WebhookEndpointController::class, 'store'])
                ->name('integrations.webhooks.store');
            Route::patch('/integrations/webhooks/{webhookEndpoint}', [WebhookEndpointController::class, 'update'])
                ->name('integrations.webhooks.update');
            Route::post('/integrations/webhooks/{webhookEndpoint}/rotate-secret', [WebhookEndpointController::class, 'rotateSecret'])
                ->name('integrations.webhooks.rotate-secret');
            Route::post('/integrations/webhooks/{webhookEndpoint}/test', [WebhookEndpointController::class, 'test'])
                ->name('integrations.webhooks.test');
            Route::get('/integrations/webhooks/{webhookEndpoint}/deliveries/{delivery}', [WebhookDeliveryController::class, 'show'])
                ->name('integrations.webhooks.deliveries.show');
            Route::post('/integrations/webhooks/{webhookEndpoint}/deliveries/{delivery}/redeliver', [WebhookDeliveryController::class, 'redeliver'])
                ->name('integrations.webhooks.deliveries.redeliver');
            Route::delete('/integrations/webhooks/{webhookEndpoint}', [WebhookEndpointController::class, 'destroy'])
                ->name('integrations.webhooks.destroy');
            Route::post('/integrations/notifications', [NotificationDestinationController::class, 'store'])
                ->name('integrations.notifications.store');
            Route::patch('/integrations/notifications/{notificationDestination}', [NotificationDestinationController::class, 'update'])
                ->name('integrations.notifications.update');
            Route::post('/integrations/notifications/{notificationDestination}/test', [NotificationDestinationController::class, 'test'])
                ->middleware('throttle:10,1')
                ->name('integrations.notifications.test');
            Route::delete('/integrations/notifications/{notificationDestination}', [NotificationDestinationController::class, 'destroy'])
                ->name('integrations.notifications.destroy');
            Route::post('/integrations/sentry', [SentryDsnController::class, 'store'])
                ->name('integrations.sentry.store');
            Route::patch('/integrations/sentry/{sentryDsn}', [SentryDsnController::class, 'update'])
                ->name('integrations.sentry.update');
            Route::delete('/integrations/sentry/{sentryDsn}', [SentryDsnController::class, 'destroy'])
                ->name('integrations.sentry.destroy');
            Route::post('/integrations/{integration}/pipelines/batch/runs', [PipelineBuildController::class, 'storeBatch'])
                ->name('integrations.pipelines.batch.runs.store');
            Route::post('/integrations/{integration}/pipelines/{pipeline}/runs', [PipelineBuildController::class, 'store'])
                ->name('integrations.pipelines.runs.store');
            Route::get('/apps', DashboardController::class)->name('apps.index');
            Route::get('/builds', TeamBuildPageController::class)->name('builds.index');
            Route::get('/blueprints', [BlueprintController::class, 'index'])->name('blueprints.index');
            Route::post('/blueprints', [BlueprintController::class, 'store'])
                ->middleware('team.permission:manage_applications')
                ->name('blueprints.store');
            Route::patch('/blueprints/{blueprint}', [BlueprintController::class, 'update'])
                ->middleware('team.permission:manage_applications')
                ->name('blueprints.update');
            Route::delete('/blueprints/{blueprint}', [BlueprintController::class, 'destroy'])
                ->middleware('team.permission:manage_applications')
                ->name('blueprints.destroy');
            Route::post('/blueprints/trash/{blueprint}/restore', [BlueprintController::class, 'restore'])
                ->middleware('team.permission:manage_applications')
                ->name('blueprints.restore');
            Route::post('/blueprints/{blueprint}/evaluate', [BlueprintController::class, 'evaluate'])
                ->middleware('team.permission:manage_applications')
                ->name('blueprints.evaluate');
            Route::post('/blueprints/{blueprint}/hooks', [BlueprintHookController::class, 'store'])
                ->middleware('team.permission:manage_applications')
                ->name('blueprints.hooks.store');
            Route::patch('/blueprints/{blueprint}/hooks/{hook}', [BlueprintHookController::class, 'update'])
                ->middleware('team.permission:manage_applications')
                ->name('blueprints.hooks.update');
            Route::delete('/blueprints/{blueprint}/hooks/{hook}', [BlueprintHookController::class, 'destroy'])
                ->middleware('team.permission:manage_applications')
                ->name('blueprints.hooks.destroy');
            Route::get('/credentials', CredentialVaultController::class)->name('credentials.index');
            Route::get('/tools/push-notifications', [PushNotificationToolController::class, 'index'])
                ->name('tools.push-notifications.index');
            Route::post('/tools/push-notifications/send', [PushNotificationToolController::class, 'send'])
                ->middleware('throttle:30,1')
                ->name('tools.push-notifications.send');
            Route::get('/api-keys', [TeamApiKeyController::class, 'index'])
                ->middleware('team.permission:manage_team')
                ->name('api-keys.index');
            Route::post('/api-keys', [TeamApiKeyController::class, 'store'])
                ->middleware('team.permission:manage_team')
                ->name('api-keys.store');
            Route::delete('/api-keys/{apiKey}', [TeamApiKeyController::class, 'destroy'])
                ->middleware('team.permission:manage_team')
                ->name('api-keys.destroy');
            Route::post('/apps', [MobileAppController::class, 'store'])->middleware('team.permission:manage_applications')->name('apps.store');
            Route::post('/apps/trash/{mobileApp}/restore', [MobileAppController::class, 'restore'])
                ->middleware('team.permission:manage_applications')
                ->name('apps.restore');
            Route::get('/apps/{mobileApp:slug}', AppOverviewController::class)->name('apps.show');
            Route::get('/apps/{mobileApp:slug}/telemetry', AppTelemetryPageController::class)
                ->name('apps.telemetry.index');
            Route::get('/apps/{mobileApp:slug}/telemetry/issues/{sentryIssue}', [AppTelemetryDetailPageController::class, 'issue'])
                ->name('apps.telemetry.issues.show');
            Route::patch('/apps/{mobileApp:slug}/telemetry/issues/{sentryIssue}', [AppTelemetryDetailPageController::class, 'updateIssue'])
                ->middleware('team.permission:manage_applications')
                ->name('apps.telemetry.issues.update');
            Route::patch('/apps/{mobileApp:slug}/telemetry/issues/{sentryIssue}/tags', [AppTelemetryDetailPageController::class, 'updateIssueTags'])
                ->middleware('team.permission:manage_applications')
                ->name('apps.telemetry.issues.tags.update');
            Route::get('/apps/{mobileApp:slug}/telemetry/traces/{traceId}', [AppTelemetryDetailPageController::class, 'trace'])
                ->name('apps.telemetry.traces.show');
            Route::get('/apps/{mobileApp:slug}/telemetry/envelopes/{sentryEnvelope}/archive', SentryArchiveController::class)
                ->name('apps.telemetry.envelopes.archive');
            Route::get('/apps/{mobileApp:slug}/media', AppMediaLibraryController::class)
                ->name('apps.media.index');
            Route::post('/apps/{mobileApp:slug}/media/regenerate', [AppMediaLibraryController::class, 'regenerate'])
                ->middleware('team.permission:manage_applications')
                ->name('apps.media.regenerate');
            Route::post('/apps/{mobileApp:slug}/media/files', [AppFileController::class, 'store'])
                ->middleware('team.permission:manage_applications')
                ->name('apps.media.files.store');
            Route::patch('/apps/{mobileApp:slug}/media/files/{media}', [AppFileController::class, 'update'])
                ->middleware('team.permission:manage_applications')
                ->name('apps.media.files.update');
            Route::get('/apps/{mobileApp:slug}/media/files/{media}/preview', [AppFileController::class, 'preview'])
                ->name('apps.media.files.preview');
            Route::get('/apps/{mobileApp:slug}/media/files/{fileName}', [AppFileController::class, 'download'])
                ->name('apps.media.files.download');
            Route::delete('/apps/{mobileApp:slug}/media/files/{media}', [AppFileController::class, 'destroy'])
                ->middleware('team.permission:manage_applications')
                ->name('apps.media.files.destroy');
            Route::patch('/apps/{mobileApp:slug}', [MobileAppController::class, 'update'])->middleware('team.permission:manage_applications')->name('apps.update');
            Route::delete('/apps/{mobileApp:slug}', [MobileAppController::class, 'destroy'])->middleware('team.permission:manage_applications')->name('apps.destroy');
            Route::get('/apps/{mobileApp:slug}/expo', ExpoAppController::class)
                ->name('apps.expo.index');
            Route::post('/apps/{mobileApp:slug}/expo/setup', [ExpoProjectController::class, 'setup'])
                ->name('apps.expo.setup');
            Route::post('/apps/{mobileApp:slug}/expo/sync', [ExpoProjectController::class, 'sync'])
                ->name('apps.expo.sync');
            Route::patch('/apps/{mobileApp:slug}/expo/automatic-sync', [ExpoProjectController::class, 'updateAutomaticSync'])
                ->name('apps.expo.automatic-sync.update');
            Route::delete('/apps/{mobileApp:slug}/expo', [ExpoProjectController::class, 'unlink'])
                ->name('apps.expo.unlink');
            Route::post('/apps/{mobileApp:slug}/expo/builds/{buildId}/cancel', [ExpoBuildController::class, 'cancel'])
                ->name('apps.expo.builds.cancel');
            Route::post('/apps/{mobileApp:slug}/expo/builds/{buildId}/retry', [ExpoBuildController::class, 'retry'])
                ->name('apps.expo.builds.retry');
            Route::post('/apps/{mobileApp:slug}/expo/builds/import', [ExpoBuildController::class, 'importBuilds'])
                ->middleware('team.permission:manage_applications')
                ->name('apps.expo.builds.import');
            Route::post('/apps/{mobileApp:slug}/expo/submissions', [ExpoSubmissionController::class, 'store'])
                ->name('apps.expo.submissions.store');
            Route::post('/apps/{mobileApp:slug}/expo/submissions/{submissionId}/cancel', [ExpoSubmissionController::class, 'cancel'])
                ->name('apps.expo.submissions.cancel');
            Route::post('/apps/{mobileApp:slug}/expo/submissions/{submissionId}/retry', [ExpoSubmissionController::class, 'retry'])
                ->name('apps.expo.submissions.retry');
            Route::post('/apps/{mobileApp:slug}/identifiers/{appIdentifier}/credentials/{credential}/expo', [ExpoCredentialController::class, 'store'])
                ->name('apps.expo.credentials.store');
            Route::post('/apps/{mobileApp:slug}/builds', [ManualBuildController::class, 'store'])
                ->name('apps.builds.store');
            Route::get('/apps/{mobileApp:slug}/builds', [AppBuildPageController::class, 'index'])
                ->name('apps.builds.index');
            Route::get('/apps/{mobileApp:slug}/builds/{buildRecord}', [AppBuildPageController::class, 'show'])
                ->name('apps.builds.show');
            Route::get('/apps/{mobileApp:slug}/builds/{buildRecord}/state', [AppBuildPageController::class, 'state'])
                ->name('apps.builds.state');
            Route::get('/apps/{mobileApp:slug}/builds/{buildRecord}/logs/archive', BuildLogArchiveController::class)
                ->name('apps.builds.logs.archive');
            Route::get('/apps/{mobileApp:slug}/builds/{buildRecord}/logs/download', BuildLogDownloadController::class)
                ->name('apps.builds.logs.download');
            Route::post('/apps/{mobileApp:slug}/builds/{buildRecord}/cancel', [PipelineBuildController::class, 'cancel'])
                ->name('apps.builds.cancel');
            Route::patch('/apps/{mobileApp:slug}/builds/{buildRecord}/status', [ForceBuildStatusController::class, 'update'])
                ->name('apps.builds.status.update');
            Route::post('/apps/{mobileApp:slug}/builds/{buildRecord}/artifacts', [BuildArtifactController::class, 'store'])
                ->name('apps.builds.artifacts.store');
            Route::get('/apps/{mobileApp:slug}/builds/{buildRecord}/artifacts/{artifact}', [BuildArtifactController::class, 'download'])
                ->name('apps.builds.artifacts.download');
            Route::delete('/apps/{mobileApp:slug}/builds/{buildRecord}/artifacts/{artifact}', [BuildArtifactController::class, 'destroy'])
                ->name('apps.builds.artifacts.destroy');
            Route::post('/apps/{mobileApp:slug}/builds/{buildRecord}/artifacts/trash/{artifact}/restore', [BuildArtifactController::class, 'restore'])
                ->middleware('team.permission:manage_applications')
                ->name('apps.builds.artifacts.restore');
            Route::patch('/apps/{mobileApp:slug}/tags', [TagsController::class, 'updateApp'])
                ->middleware('team.permission:manage_applications')
                ->name('apps.tags.update');
            Route::patch('/apps/{mobileApp:slug}/builds/{buildRecord}/tags', [TagsController::class, 'updateBuild'])
                ->middleware('team.permission:manage_applications')
                ->name('apps.builds.tags.update');
            Route::patch('/apps/{mobileApp:slug}/builds/{buildRecord}/artifacts/{artifact}/tags', [TagsController::class, 'updateArtifact'])
                ->middleware('team.permission:manage_applications')
                ->name('apps.builds.artifacts.tags.update');
            Route::patch('/credentials/{credential}/tags', [TagsController::class, 'updateCredential'])
                ->middleware(['team.permission:manage_credentials', 'owns.model:credential,team_id,team'])
                ->name('credentials.tags.update');
            Route::post('/apps/{mobileApp:slug}/identifiers', [AppIdentifierController::class, 'store'])->middleware('team.permission:manage_applications')->name('apps.identifiers.store');
            Route::post('/apps/{mobileApp:slug}/identifiers/sync', [AppIdentifierPageController::class, 'sync'])
                ->middleware(['team.permission:manage_applications', 'team.permission:manage_credentials'])
                ->name('apps.identifiers.sync');
            Route::patch('/apps/{mobileApp:slug}/identifiers/{appIdentifier}', [AppIdentifierController::class, 'update'])->middleware('team.permission:manage_applications')->name('apps.identifiers.update');
            Route::delete('/apps/{mobileApp:slug}/identifiers/{appIdentifier}', [AppIdentifierController::class, 'destroy'])->middleware('team.permission:manage_applications')->name('apps.identifiers.destroy');
            Route::post('/apps/{mobileApp:slug}/identifiers/trash/{appIdentifier}/restore', [AppIdentifierController::class, 'restore'])->middleware('team.permission:manage_applications')->name('apps.identifiers.restore');
            Route::get('/apps/{mobileApp:slug}/identifiers', [AppIdentifierPageController::class, 'index'])->name('apps.identifiers.index');
            Route::get('/apps/{mobileApp:slug}/identifiers/{appIdentifier}', [AppIdentifierPageController::class, 'show'])->name('apps.identifiers.show');
            Route::post('/apps/{mobileApp:slug}/identifiers/{appIdentifier}/credentials', [CredentialController::class, 'attach'])
                ->middleware('team.permission:manage_credentials')
                ->name('apps.identifiers.credentials.attach');
            Route::delete('/apps/{mobileApp:slug}/identifiers/{appIdentifier}/credentials/{credential}', [CredentialController::class, 'detach'])
                ->middleware('team.permission:manage_credentials')
                ->name('apps.identifiers.credentials.detach');
            Route::post('/apps/{mobileApp:slug}/credentials', [CredentialController::class, 'store'])
                ->middleware('team.permission:manage_credentials')
                ->name('apps.credentials.store');
            Route::post('/apps/{mobileApp:slug}/assets/{collection}', [AppAssetController::class, 'upload'])->middleware('team.permission:manage_applications')->name('apps.assets.upload');
            Route::get('/apps/{mobileApp:slug}/assets/{collection}/conversions/{conversion}/preview', [AppAssetController::class, 'previewConversion'])
                ->name('apps.assets.conversions.preview');
            Route::get('/apps/{mobileApp:slug}/assets/{collection}/conversions/{conversion}', [AppAssetController::class, 'downloadConversion'])
                ->name('apps.assets.conversions.download');
            Route::get('/apps/{mobileApp:slug}/assets/{collection}/preview', [AppAssetController::class, 'preview'])->name('apps.assets.preview');
            Route::get('/apps/{mobileApp:slug}/assets/{collection}', [AppAssetController::class, 'download'])->name('apps.assets.download');
            Route::post('/credentials/{credential}/file', [CredentialController::class, 'upload'])
                ->middleware(['team.permission:manage_credentials', 'owns.model:credential,team_id,team'])
                ->name('credentials.upload');
            Route::get('/credentials/{credential}/file', [CredentialController::class, 'download'])
                ->middleware('owns.model:credential,team_id,team')
                ->name('credentials.download');
            Route::post('/credentials/{credential}/reveal', [CredentialController::class, 'reveal'])
                ->middleware([
                    'team.permission:manage_credentials',
                    'throttle:6,1',
                    'owns.model:credential,team_id,team',
                ])
                ->name('credentials.reveal');
            Route::post('/credentials/{credential}', [CredentialController::class, 'update'])
                ->middleware(['team.permission:manage_credentials', 'owns.model:credential,team_id,team'])
                ->name('credentials.update');
            Route::delete('/credentials/{credential}', [CredentialController::class, 'destroy'])
                ->middleware(['team.permission:manage_credentials', 'owns.model:credential,team_id,team'])
                ->name('credentials.destroy');
            Route::post('/credentials/trash/{credential}/restore', [CredentialController::class, 'restore'])
                ->middleware('team.permission:manage_credentials')
                ->name('credentials.restore');
            Route::get('/audit', [AuditLogController::class, 'index'])->name('audit.index');
            Route::put('/switch', [TeamController::class, 'switch'])->name('switch');
        });

});

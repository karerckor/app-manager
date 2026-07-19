<?php

use App\Http\Controllers\AppAssetController;
use App\Http\Controllers\AppIdentifierController;
use App\Http\Controllers\BuildArtifactController;
use App\Http\Controllers\CredentialController;
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\ForceBuildStatusController;
use App\Http\Controllers\Integrations\CiCdPipelineController;
use App\Http\Controllers\Integrations\GitHubConnectionController;
use App\Http\Controllers\Integrations\GitHubDiscoveryController;
use App\Http\Controllers\Integrations\IntegrationIndexController;
use App\Http\Controllers\Integrations\PipelineBuildController;
use App\Http\Controllers\Integrations\WebhookEndpointController;
use App\Http\Controllers\ManualBuildController;
use App\Http\Controllers\MobileAppController;
use App\Http\Controllers\TagsController;
use App\Http\Controllers\TeamApiKeyController;
use App\Http\Controllers\TeamController;
use App\Http\Controllers\Webhooks\GitHubWebhookController;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

Route::get('/', function () {
    return auth()->check() ? redirect()->route('dashboard') : redirect()->route('login');
});

Route::post('/webhooks/github', GitHubWebhookController::class)
    ->name('webhooks.github');

Route::middleware('auth')->group(function () {
    Route::get('/dashboard', function (Request $request) {
        $team = $request->user()->currentTeam;
        abort_unless($team, 404);

        return redirect()->route('accounts.apps.index', ['team' => $team]);
    })->name('dashboard');

    Route::post('/accounts', [TeamController::class, 'store'])->name('accounts.store');
    Route::get('/integrations/github/callback', [GitHubConnectionController::class, 'callback'])
        ->name('integrations.github.callback');

    Route::prefix('/accounts/{team:slug}')->scopeBindings()->name('accounts.')->group(function () {
        Route::get('/integrations', IntegrationIndexController::class)->name('integrations.index');
        Route::get('/integrations/github/connect', [GitHubConnectionController::class, 'connect'])
            ->name('integrations.github.connect');
        Route::post('/integrations/{integration}/github/sync', [GitHubConnectionController::class, 'sync'])
            ->name('integrations.github.sync');
        Route::delete('/integrations/{integration}/github', [GitHubConnectionController::class, 'disconnect'])
            ->name('integrations.github.disconnect');
        Route::get('/integrations/{integration}/repositories', [GitHubDiscoveryController::class, 'repositories'])
            ->name('integrations.repositories.index');
        Route::get('/integrations/{integration}/workflows', [GitHubDiscoveryController::class, 'workflows'])
            ->name('integrations.workflows.index');
        Route::get('/integrations/{integration}/refs', [GitHubDiscoveryController::class, 'refs'])
            ->name('integrations.refs.index');
        Route::post('/integrations/{integration}/pipelines', [CiCdPipelineController::class, 'store'])
            ->name('integrations.pipelines.store');
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
        Route::delete('/integrations/webhooks/{webhookEndpoint}', [WebhookEndpointController::class, 'destroy'])
            ->name('integrations.webhooks.destroy');
        Route::post('/integrations/{integration}/pipelines/{pipeline}/runs', [PipelineBuildController::class, 'store'])
            ->name('integrations.pipelines.runs.store');
        Route::get('/apps', DashboardController::class)->name('apps.index');
        Route::get('/api-keys', [TeamApiKeyController::class, 'index'])->name('api-keys.index');
        Route::post('/api-keys', [TeamApiKeyController::class, 'store'])->name('api-keys.store');
        Route::delete('/api-keys/{apiKey}', [TeamApiKeyController::class, 'destroy'])->name('api-keys.destroy');
        Route::post('/apps', [MobileAppController::class, 'store'])->name('apps.store');
        Route::get('/apps/{mobileApp:slug}', [MobileAppController::class, 'show'])->name('apps.show');
        Route::patch('/apps/{mobileApp:slug}', [MobileAppController::class, 'update'])->name('apps.update');
        Route::delete('/apps/{mobileApp:slug}', [MobileAppController::class, 'destroy'])->name('apps.destroy');
        Route::post('/apps/{mobileApp:slug}/builds', [ManualBuildController::class, 'store'])
            ->name('apps.builds.store');
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
        Route::patch('/apps/{mobileApp:slug}/tags', [TagsController::class, 'updateApp'])
            ->name('apps.tags.update');
        Route::patch('/apps/{mobileApp:slug}/builds/{buildRecord}/tags', [TagsController::class, 'updateBuild'])
            ->name('apps.builds.tags.update');
        Route::patch('/apps/{mobileApp:slug}/builds/{buildRecord}/artifacts/{artifact}/tags', [TagsController::class, 'updateArtifact'])
            ->name('apps.builds.artifacts.tags.update');
        Route::patch('/credentials/{credential}/tags', [TagsController::class, 'updateCredential'])
            ->name('credentials.tags.update');
        Route::post('/apps/{mobileApp:slug}/identifiers', [AppIdentifierController::class, 'store'])->name('apps.identifiers.store');
        Route::patch('/apps/{mobileApp:slug}/identifiers/{appIdentifier}', [AppIdentifierController::class, 'update'])->name('apps.identifiers.update');
        Route::delete('/apps/{mobileApp:slug}/identifiers/{appIdentifier}', [AppIdentifierController::class, 'destroy'])->name('apps.identifiers.destroy');
        Route::post('/apps/{mobileApp:slug}/credentials', [CredentialController::class, 'store'])->name('apps.credentials.store');
        Route::post('/apps/{mobileApp:slug}/assets/{collection}', [AppAssetController::class, 'upload'])->name('apps.assets.upload');
        Route::get('/apps/{mobileApp:slug}/assets/{collection}/preview', [AppAssetController::class, 'preview'])->name('apps.assets.preview');
        Route::get('/apps/{mobileApp:slug}/assets/{collection}', [AppAssetController::class, 'download'])->name('apps.assets.download');
        Route::post('/credentials/{credential}/file', [CredentialController::class, 'upload'])->name('credentials.upload');
        Route::get('/credentials/{credential}/file', [CredentialController::class, 'download'])->name('credentials.download');
        Route::post('/credentials/{credential}', [CredentialController::class, 'update'])->name('credentials.update');
        Route::delete('/credentials/{credential}', [CredentialController::class, 'destroy'])->name('credentials.destroy');
        Route::put('/switch', [TeamController::class, 'switch'])->name('switch');
    });

});

<?php

use App\Http\Controllers\Api\V1\AppAssetController;
use App\Http\Controllers\Api\V1\AppIdentifierController;
use App\Http\Controllers\Api\V1\BuildController;
use App\Http\Controllers\Api\V1\BuildLogController;
use App\Http\Controllers\Api\V1\CredentialController;
use App\Http\Controllers\Api\V1\CredentialTypeController;
use App\Http\Controllers\Api\V1\IntegrationController;
use App\Http\Controllers\Api\V1\IntegrationDiscoveryController;
use App\Http\Controllers\Api\V1\MobileAppController;
use Illuminate\Support\Facades\Route;

Route::prefix('v1/{team:slug}')
    ->middleware(['auth:sanctum', 'team.member'])
    ->scopeBindings()
    ->name('api.v1.')
    ->group(function () {
        Route::get('/credential-types', CredentialTypeController::class)
            ->middleware(['abilities:credentials:read', 'team.permission:view'])
            ->name('credential-types.index');

        Route::get('/integrations', [IntegrationController::class, 'index'])->middleware(['abilities:integrations:read', 'team.permission:manage_integrations'])->name('integrations.index');
        Route::get('/integrations/{integration}', [IntegrationController::class, 'show'])->middleware(['abilities:integrations:read', 'team.permission:manage_integrations'])->name('integrations.show');

        Route::get('/integrations/{integration}/repositories', [IntegrationDiscoveryController::class, 'repositories'])->middleware(['abilities:integrations:read', 'team.permission:manage_integrations'])->name('integrations.repositories.index');
        Route::get('/integrations/{integration}/workflows', [IntegrationDiscoveryController::class, 'workflows'])->middleware(['abilities:integrations:read', 'team.permission:manage_integrations'])->name('integrations.workflows.index');
        Route::get('/integrations/{integration}/refs', [IntegrationDiscoveryController::class, 'refs'])->middleware(['abilities:integrations:read', 'team.permission:manage_integrations'])->name('integrations.refs.index');

        Route::get('/apps', [MobileAppController::class, 'index'])->middleware(['abilities:apps:read', 'team.permission:view'])->name('apps.index');
        Route::post('/apps', [MobileAppController::class, 'store'])->middleware(['abilities:apps:write', 'team.permission:manage_applications'])->name('apps.store');
        Route::get('/apps/{mobileApp:slug}', [MobileAppController::class, 'show'])->middleware(['abilities:apps:read', 'team.permission:view'])->name('apps.show');
        Route::patch('/apps/{mobileApp:slug}', [MobileAppController::class, 'update'])->middleware(['abilities:apps:write', 'team.permission:manage_applications'])->name('apps.update');
        Route::delete('/apps/{mobileApp:slug}', [MobileAppController::class, 'destroy'])->middleware(['abilities:apps:write', 'team.permission:manage_applications'])->name('apps.destroy');

        Route::get('/apps/{mobileApp:slug}/assets/{asset}', [AppAssetController::class, 'show'])
            ->middleware(['abilities:assets:read', 'team.permission:view'])
            ->whereIn('asset', ['icon', 'splashscreen'])
            ->name('apps.assets.show');
        Route::match(['post', 'put'], '/apps/{mobileApp:slug}/assets/{asset}', [AppAssetController::class, 'store'])
            ->middleware(['abilities:assets:write', 'team.permission:manage_applications'])
            ->whereIn('asset', ['icon', 'splashscreen'])
            ->name('apps.assets.store');
        Route::delete('/apps/{mobileApp:slug}/assets/{asset}', [AppAssetController::class, 'destroy'])
            ->middleware(['abilities:assets:write', 'team.permission:manage_applications'])
            ->whereIn('asset', ['icon', 'splashscreen'])
            ->name('apps.assets.destroy');

        Route::get('/apps/{mobileApp:slug}/identifiers', [AppIdentifierController::class, 'index'])->middleware(['abilities:identifiers:read', 'team.permission:view'])->name('apps.identifiers.index');
        Route::post('/apps/{mobileApp:slug}/identifiers', [AppIdentifierController::class, 'store'])->middleware(['abilities:identifiers:write', 'team.permission:manage_applications'])->name('apps.identifiers.store');
        Route::get('/apps/{mobileApp:slug}/identifiers/{appIdentifier}', [AppIdentifierController::class, 'show'])->middleware(['abilities:identifiers:read', 'team.permission:view'])->name('apps.identifiers.show');
        Route::patch('/apps/{mobileApp:slug}/identifiers/{appIdentifier}', [AppIdentifierController::class, 'update'])->middleware(['abilities:identifiers:write', 'team.permission:manage_applications'])->name('apps.identifiers.update');
        Route::delete('/apps/{mobileApp:slug}/identifiers/{appIdentifier}', [AppIdentifierController::class, 'destroy'])->middleware(['abilities:identifiers:write', 'team.permission:manage_applications'])->name('apps.identifiers.destroy');

        Route::get('/apps/{mobileApp:slug}/credentials', [CredentialController::class, 'index'])->middleware(['abilities:credentials:read', 'team.permission:view'])->name('apps.credentials.index');
        Route::post('/apps/{mobileApp:slug}/credentials', [CredentialController::class, 'store'])->middleware(['abilities:credentials:write', 'team.permission:manage_credentials'])->name('apps.credentials.store');
        Route::get('/apps/{mobileApp:slug}/credentials/{credential}', [CredentialController::class, 'show'])->middleware(['abilities:credentials:read', 'team.permission:view'])->name('apps.credentials.show');
        Route::patch('/apps/{mobileApp:slug}/credentials/{credential}', [CredentialController::class, 'update'])->middleware(['abilities:credentials:write', 'team.permission:manage_credentials'])->name('apps.credentials.update');
        Route::delete('/apps/{mobileApp:slug}/credentials/{credential}', [CredentialController::class, 'destroy'])->middleware(['abilities:credentials:write', 'team.permission:manage_credentials'])->name('apps.credentials.destroy');
        Route::get('/apps/{mobileApp:slug}/credentials/{credential}/file', [CredentialController::class, 'download'])->middleware(['abilities:credentials:read', 'team.permission:view'])->name('apps.credentials.file.show');
        Route::post('/apps/{mobileApp:slug}/credentials/{credential}/file', [CredentialController::class, 'upload'])->middleware(['abilities:credentials:write', 'team.permission:manage_credentials'])->name('apps.credentials.file.store');
        Route::delete('/apps/{mobileApp:slug}/credentials/{credential}/file', [CredentialController::class, 'destroyFile'])->middleware(['abilities:credentials:write', 'team.permission:manage_credentials'])->name('apps.credentials.file.destroy');

        Route::get('/apps/{mobileApp:slug}/builds', [BuildController::class, 'index'])->middleware(['abilities:builds:read', 'team.permission:view'])->name('apps.builds.index');
        Route::post('/apps/{mobileApp:slug}/builds', [BuildController::class, 'store'])->middleware(['abilities:builds:write', 'team.permission:manage_applications'])->name('apps.builds.store');
        Route::get('/apps/{mobileApp:slug}/builds/{buildRecord}', [BuildController::class, 'show'])->middleware(['abilities:builds:read', 'team.permission:view'])->name('apps.builds.show');
        Route::patch('/apps/{mobileApp:slug}/builds/{buildRecord}', [BuildController::class, 'update'])->middleware(['abilities:builds:write', 'team.permission:manage_applications'])->name('apps.builds.update');
        Route::get('/apps/{mobileApp:slug}/builds/{buildRecord}/logs', [BuildLogController::class, 'show'])->middleware(['abilities:logs:read', 'team.permission:view'])->name('apps.builds.logs.show');
        Route::post('/apps/{mobileApp:slug}/builds/{buildRecord}/logs', [BuildLogController::class, 'store'])->middleware(['abilities:logs:write', 'team.permission:manage_applications'])->name('apps.builds.logs.store');
    });

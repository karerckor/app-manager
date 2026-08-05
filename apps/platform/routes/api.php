<?php

use App\Http\Controllers\Api\V1\AppAssetController;
use App\Http\Controllers\Api\V1\AppIdentifierController;
use App\Http\Controllers\Api\V1\BuildContextController;
use App\Http\Controllers\Api\V1\BuildLogController;
use App\Http\Controllers\Api\V1\BuildRecordController;
use App\Http\Controllers\Api\V1\CredentialController;
use App\Http\Controllers\Api\V1\CredentialTypeController;
use App\Http\Controllers\Api\V1\MobileAppController;
use Illuminate\Support\Facades\Route;

Route::prefix('v1/{team:slug}')
    ->middleware(['auth:sanctum', 'team.member'])
    ->scopeBindings()
    ->name('api.v1.')
    ->group(function () {
        Route::get('/credential-types', CredentialTypeController::class)
            ->middleware('abilities:apps:read')
            ->name('credential-types.index');

        Route::get('/apps', [MobileAppController::class, 'index'])->middleware('abilities:apps:read')->name('apps.index');
        Route::post('/apps', [MobileAppController::class, 'store'])->middleware('abilities:apps:read,apps:write')->name('apps.store');
        Route::get('/apps/{mobileApp:slug}', [MobileAppController::class, 'show'])->middleware('abilities:apps:read')->name('apps.show');
        Route::patch('/apps/{mobileApp:slug}', [MobileAppController::class, 'update'])->middleware('abilities:apps:read,apps:write')->name('apps.update');
        Route::delete('/apps/{mobileApp:slug}', [MobileAppController::class, 'destroy'])->middleware('abilities:apps:read,apps:write')->name('apps.destroy');

        Route::get('/apps/{mobileApp:slug}/build-context', BuildContextController::class)
            ->middleware('abilities:apps:read,secrets:read')
            ->name('apps.build-context.show');
        Route::get('/apps/{mobileApp:slug}/builds', [BuildRecordController::class, 'index'])
            ->middleware('abilities:apps:read,builds:read')
            ->name('apps.builds.index');
        Route::post('/apps/{mobileApp:slug}/builds', [BuildRecordController::class, 'store'])
            ->middleware('abilities:apps:read,builds:read,builds:write')
            ->name('apps.builds.store');
        Route::get('/apps/{mobileApp:slug}/builds/external/{buildRecord:external_id}', [BuildRecordController::class, 'show'])
            ->middleware('abilities:apps:read,builds:read')
            ->where('buildRecord', '[A-Za-z0-9][A-Za-z0-9._:-]*')
            ->name('apps.builds.external.show');
        Route::get('/apps/{mobileApp:slug}/builds/{buildRecord}', [BuildRecordController::class, 'show'])
            ->middleware('abilities:apps:read,builds:read')
            ->name('apps.builds.show');
        Route::post('/apps/{mobileApp:slug}/builds/{buildRecord}/start', [BuildRecordController::class, 'start'])
            ->middleware('abilities:apps:read,builds:read,builds:write')
            ->name('apps.builds.start');
        Route::patch('/apps/{mobileApp:slug}/builds/{buildRecord}', [BuildRecordController::class, 'update'])
            ->middleware('abilities:apps:read,builds:read,builds:write')
            ->name('apps.builds.update');
        Route::get('/apps/{mobileApp:slug}/builds/{buildRecord}/logs', [BuildLogController::class, 'index'])
            ->middleware('abilities:apps:read,builds:read')
            ->name('apps.builds.logs.index');
        Route::post('/apps/{mobileApp:slug}/builds/{buildRecord}/logs', [BuildLogController::class, 'store'])
            ->middleware('abilities:apps:read,builds:read,builds:write')
            ->name('apps.builds.logs.store');

        Route::get('/apps/{mobileApp:slug}/assets/{asset}', [AppAssetController::class, 'show'])
            ->middleware('abilities:apps:read')
            ->whereIn('asset', ['icon', 'splashscreen'])
            ->name('apps.assets.show');
        Route::match(['post', 'put'], '/apps/{mobileApp:slug}/assets/{asset}', [AppAssetController::class, 'store'])
            ->middleware('abilities:apps:read,apps:write')
            ->whereIn('asset', ['icon', 'splashscreen'])
            ->name('apps.assets.store');
        Route::delete('/apps/{mobileApp:slug}/assets/{asset}', [AppAssetController::class, 'destroy'])
            ->middleware('abilities:apps:read,apps:write')
            ->whereIn('asset', ['icon', 'splashscreen'])
            ->name('apps.assets.destroy');

        Route::get('/apps/{mobileApp:slug}/identifiers', [AppIdentifierController::class, 'index'])->middleware('abilities:apps:read')->name('apps.identifiers.index');
        Route::post('/apps/{mobileApp:slug}/identifiers', [AppIdentifierController::class, 'store'])->middleware('abilities:apps:read,apps:write')->name('apps.identifiers.store');
        Route::get('/apps/{mobileApp:slug}/identifiers/{appIdentifier}', [AppIdentifierController::class, 'show'])->middleware('abilities:apps:read')->name('apps.identifiers.show');
        Route::patch('/apps/{mobileApp:slug}/identifiers/{appIdentifier}', [AppIdentifierController::class, 'update'])->middleware('abilities:apps:read,apps:write')->name('apps.identifiers.update');
        Route::delete('/apps/{mobileApp:slug}/identifiers/{appIdentifier}', [AppIdentifierController::class, 'destroy'])->middleware('abilities:apps:read,apps:write')->name('apps.identifiers.destroy');

        Route::get('/apps/{mobileApp:slug}/credentials', [CredentialController::class, 'index'])->middleware('abilities:apps:read,secrets:read')->name('apps.credentials.index');
        Route::post('/apps/{mobileApp:slug}/credentials', [CredentialController::class, 'store'])->middleware('abilities:apps:read,secrets:read,secrets:write')->name('apps.credentials.store');
        Route::get('/apps/{mobileApp:slug}/credentials/{credential}', [CredentialController::class, 'show'])->middleware('abilities:apps:read,secrets:read')->name('apps.credentials.show');
        Route::patch('/apps/{mobileApp:slug}/credentials/{credential}', [CredentialController::class, 'update'])->middleware('abilities:apps:read,secrets:read,secrets:write')->name('apps.credentials.update');
        Route::delete('/apps/{mobileApp:slug}/credentials/{credential}', [CredentialController::class, 'destroy'])->middleware('abilities:apps:read,secrets:read,secrets:write')->name('apps.credentials.destroy');
        Route::get('/apps/{mobileApp:slug}/credentials/{credential}/file', [CredentialController::class, 'download'])->middleware('abilities:apps:read,secrets:read')->name('apps.credentials.file.show');
        Route::post('/apps/{mobileApp:slug}/credentials/{credential}/file', [CredentialController::class, 'upload'])->middleware('abilities:apps:read,secrets:read,secrets:write')->name('apps.credentials.file.store');
        Route::delete('/apps/{mobileApp:slug}/credentials/{credential}/file', [CredentialController::class, 'destroyFile'])->middleware('abilities:apps:read,secrets:read,secrets:write')->name('apps.credentials.file.destroy');
    });

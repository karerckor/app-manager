<?php

use App\Http\Controllers\Api\V1\AppAssetController;
use App\Http\Controllers\Api\V1\AppIdentifierController;
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
            ->middleware('abilities:credentials:read')
            ->name('credential-types.index');

        Route::get('/apps', [MobileAppController::class, 'index'])->middleware('abilities:apps:read')->name('apps.index');
        Route::post('/apps', [MobileAppController::class, 'store'])->middleware('abilities:apps:write')->name('apps.store');
        Route::get('/apps/{mobileApp:slug}', [MobileAppController::class, 'show'])->middleware('abilities:apps:read')->name('apps.show');
        Route::patch('/apps/{mobileApp:slug}', [MobileAppController::class, 'update'])->middleware('abilities:apps:write')->name('apps.update');
        Route::delete('/apps/{mobileApp:slug}', [MobileAppController::class, 'destroy'])->middleware('abilities:apps:write')->name('apps.destroy');

        Route::get('/apps/{mobileApp:slug}/assets/{asset}', [AppAssetController::class, 'show'])
            ->middleware('abilities:assets:read')
            ->whereIn('asset', ['icon', 'splashscreen'])
            ->name('apps.assets.show');
        Route::match(['post', 'put'], '/apps/{mobileApp:slug}/assets/{asset}', [AppAssetController::class, 'store'])
            ->middleware('abilities:assets:write')
            ->whereIn('asset', ['icon', 'splashscreen'])
            ->name('apps.assets.store');
        Route::delete('/apps/{mobileApp:slug}/assets/{asset}', [AppAssetController::class, 'destroy'])
            ->middleware('abilities:assets:write')
            ->whereIn('asset', ['icon', 'splashscreen'])
            ->name('apps.assets.destroy');

        Route::get('/apps/{mobileApp:slug}/identifiers', [AppIdentifierController::class, 'index'])->middleware('abilities:identifiers:read')->name('apps.identifiers.index');
        Route::post('/apps/{mobileApp:slug}/identifiers', [AppIdentifierController::class, 'store'])->middleware('abilities:identifiers:write')->name('apps.identifiers.store');
        Route::get('/apps/{mobileApp:slug}/identifiers/{appIdentifier}', [AppIdentifierController::class, 'show'])->middleware('abilities:identifiers:read')->name('apps.identifiers.show');
        Route::patch('/apps/{mobileApp:slug}/identifiers/{appIdentifier}', [AppIdentifierController::class, 'update'])->middleware('abilities:identifiers:write')->name('apps.identifiers.update');
        Route::delete('/apps/{mobileApp:slug}/identifiers/{appIdentifier}', [AppIdentifierController::class, 'destroy'])->middleware('abilities:identifiers:write')->name('apps.identifiers.destroy');

        Route::get('/apps/{mobileApp:slug}/credentials', [CredentialController::class, 'index'])->middleware('abilities:credentials:read')->name('apps.credentials.index');
        Route::post('/apps/{mobileApp:slug}/credentials', [CredentialController::class, 'store'])->middleware('abilities:credentials:write')->name('apps.credentials.store');
        Route::get('/apps/{mobileApp:slug}/credentials/{credential}', [CredentialController::class, 'show'])->middleware('abilities:credentials:read')->name('apps.credentials.show');
        Route::patch('/apps/{mobileApp:slug}/credentials/{credential}', [CredentialController::class, 'update'])->middleware('abilities:credentials:write')->name('apps.credentials.update');
        Route::delete('/apps/{mobileApp:slug}/credentials/{credential}', [CredentialController::class, 'destroy'])->middleware('abilities:credentials:write')->name('apps.credentials.destroy');
        Route::get('/apps/{mobileApp:slug}/credentials/{credential}/file', [CredentialController::class, 'download'])->middleware('abilities:credentials:read')->name('apps.credentials.file.show');
        Route::post('/apps/{mobileApp:slug}/credentials/{credential}/file', [CredentialController::class, 'upload'])->middleware('abilities:credentials:write')->name('apps.credentials.file.store');
        Route::delete('/apps/{mobileApp:slug}/credentials/{credential}/file', [CredentialController::class, 'destroyFile'])->middleware('abilities:credentials:write')->name('apps.credentials.file.destroy');
    });

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
        Route::get('/credential-types', CredentialTypeController::class)->name('credential-types.index');

        Route::get('/apps', [MobileAppController::class, 'index'])->name('apps.index');
        Route::post('/apps', [MobileAppController::class, 'store'])->name('apps.store');
        Route::get('/apps/{mobileApp:slug}', [MobileAppController::class, 'show'])->name('apps.show');
        Route::patch('/apps/{mobileApp:slug}', [MobileAppController::class, 'update'])->name('apps.update');
        Route::delete('/apps/{mobileApp:slug}', [MobileAppController::class, 'destroy'])->name('apps.destroy');

        Route::get('/apps/{mobileApp:slug}/assets/{asset}', [AppAssetController::class, 'show'])
            ->whereIn('asset', ['icon', 'splashscreen'])
            ->name('apps.assets.show');
        Route::match(['post', 'put'], '/apps/{mobileApp:slug}/assets/{asset}', [AppAssetController::class, 'store'])
            ->whereIn('asset', ['icon', 'splashscreen'])
            ->name('apps.assets.store');
        Route::delete('/apps/{mobileApp:slug}/assets/{asset}', [AppAssetController::class, 'destroy'])
            ->whereIn('asset', ['icon', 'splashscreen'])
            ->name('apps.assets.destroy');

        Route::get('/apps/{mobileApp:slug}/identifiers', [AppIdentifierController::class, 'index'])->name('apps.identifiers.index');
        Route::post('/apps/{mobileApp:slug}/identifiers', [AppIdentifierController::class, 'store'])->name('apps.identifiers.store');
        Route::get('/apps/{mobileApp:slug}/identifiers/{appIdentifier}', [AppIdentifierController::class, 'show'])->name('apps.identifiers.show');
        Route::patch('/apps/{mobileApp:slug}/identifiers/{appIdentifier}', [AppIdentifierController::class, 'update'])->name('apps.identifiers.update');
        Route::delete('/apps/{mobileApp:slug}/identifiers/{appIdentifier}', [AppIdentifierController::class, 'destroy'])->name('apps.identifiers.destroy');

        Route::get('/apps/{mobileApp:slug}/credentials', [CredentialController::class, 'index'])->name('apps.credentials.index');
        Route::post('/apps/{mobileApp:slug}/credentials', [CredentialController::class, 'store'])->name('apps.credentials.store');
        Route::get('/apps/{mobileApp:slug}/credentials/{credential}', [CredentialController::class, 'show'])->name('apps.credentials.show');
        Route::patch('/apps/{mobileApp:slug}/credentials/{credential}', [CredentialController::class, 'update'])->name('apps.credentials.update');
        Route::delete('/apps/{mobileApp:slug}/credentials/{credential}', [CredentialController::class, 'destroy'])->name('apps.credentials.destroy');
        Route::get('/apps/{mobileApp:slug}/credentials/{credential}/file', [CredentialController::class, 'download'])->name('apps.credentials.file.show');
        Route::post('/apps/{mobileApp:slug}/credentials/{credential}/file', [CredentialController::class, 'upload'])->name('apps.credentials.file.store');
        Route::delete('/apps/{mobileApp:slug}/credentials/{credential}/file', [CredentialController::class, 'destroyFile'])->name('apps.credentials.file.destroy');
    });

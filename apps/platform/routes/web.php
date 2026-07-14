<?php

use App\Http\Controllers\ApiTokenController;
use App\Http\Controllers\AppAssetController;
use App\Http\Controllers\AppIdentifierController;
use App\Http\Controllers\CredentialController;
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\MobileAppController;
use App\Http\Controllers\TeamController;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

Route::get('/', function () {
    return auth()->check() ? redirect()->route('dashboard') : redirect()->route('login');
});

Route::middleware('auth')->group(function () {
    Route::get('/dashboard', function (Request $request) {
        $team = $request->user()->currentTeam;
        abort_unless($team, 404);

        return redirect()->route('accounts.apps.index', ['team' => $team]);
    })->name('dashboard');

    Route::post('/accounts', [TeamController::class, 'store'])->name('accounts.store');

    Route::prefix('/accounts/{team:slug}')->scopeBindings()->name('accounts.')->group(function () {
        Route::get('/apps', DashboardController::class)->name('apps.index');
        Route::post('/apps', [MobileAppController::class, 'store'])->name('apps.store');
        Route::get('/apps/{mobileApp:slug}', [MobileAppController::class, 'show'])->name('apps.show');
        Route::post('/apps/{mobileApp:slug}/identifiers', [AppIdentifierController::class, 'store'])->name('apps.identifiers.store');
        Route::patch('/apps/{mobileApp:slug}/identifiers/{appIdentifier}', [AppIdentifierController::class, 'update'])->name('apps.identifiers.update');
        Route::post('/apps/{mobileApp:slug}/credentials', [CredentialController::class, 'store'])->name('apps.credentials.store');
        Route::post('/apps/{mobileApp:slug}/assets/{collection}', [AppAssetController::class, 'upload'])->name('apps.assets.upload');
        Route::get('/apps/{mobileApp:slug}/assets/{collection}/preview', [AppAssetController::class, 'preview'])->name('apps.assets.preview');
        Route::get('/apps/{mobileApp:slug}/assets/{collection}', [AppAssetController::class, 'download'])->name('apps.assets.download');
        Route::post('/credentials/{credential}/file', [CredentialController::class, 'upload'])->name('credentials.upload');
        Route::get('/credentials/{credential}/file', [CredentialController::class, 'download'])->name('credentials.download');
        Route::post('/credentials/{credential}', [CredentialController::class, 'update'])->name('credentials.update');
        Route::put('/switch', [TeamController::class, 'switch'])->name('switch');
    });

    Route::post('/api-tokens', [ApiTokenController::class, 'store'])->name('api-tokens.store');
    Route::delete('/api-tokens/{token}', [ApiTokenController::class, 'destroy'])->name('api-tokens.destroy');
});

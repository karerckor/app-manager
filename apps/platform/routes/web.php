<?php

use App\Http\Controllers\Api\V1\BuildLogController as ApiBuildLogController;
use App\Http\Controllers\ApiTokenController;
use App\Http\Controllers\AppAssetController;
use App\Http\Controllers\AppIdentifierController;
use App\Http\Controllers\BuildLifecycleController;
use App\Http\Controllers\CredentialAttachmentController;
use App\Http\Controllers\CredentialController;
use App\Http\Controllers\CredentialVaultController;
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\MobileAppController;
use App\Http\Controllers\TeamAccessController;
use App\Http\Controllers\TeamController;
use App\Http\Controllers\TeamInvitationController;
use App\Http\Controllers\TeamMemberController;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use Inertia\Inertia;

Route::get('/', function () {
    return auth()->check() ? redirect()->route('dashboard') : redirect()->route('login');
});

Route::middleware('auth')->group(function () {
    Route::get('/dashboard', function (Request $request) {
        $team = $request->user()->currentTeam;

        if (! $team) {
            return Inertia::render('Teams/Empty', [
                'createTeamUrl' => route('accounts.store'),
            ]);
        }

        return redirect()->route('accounts.apps.index', ['team' => $team]);
    })->name('dashboard');

    Route::post('/accounts', [TeamController::class, 'store'])->name('accounts.store');
    Route::get('/team-invitations/{invitation}/accept', [TeamInvitationController::class, 'accept'])
        ->middleware('signed')
        ->name('team-invitations.accept');

    Route::prefix('/accounts/{team:slug}')->scopeBindings()->name('accounts.')->group(function () {
        Route::delete('/', [TeamController::class, 'destroy'])->name('destroy');
        Route::get('/access', TeamAccessController::class)->name('access');
        Route::patch('/members/{user}', [TeamMemberController::class, 'update'])->name('members.update');
        Route::delete('/members/{user}', [TeamMemberController::class, 'destroy'])->name('members.destroy');
        Route::post('/invitations', [TeamInvitationController::class, 'store'])->name('invitations.store');
        Route::delete('/invitations/{invitation}', [TeamInvitationController::class, 'destroy'])->name('invitations.destroy');
        Route::get('/apps', DashboardController::class)->name('apps.index');
        Route::get('/credentials', CredentialVaultController::class)->name('credentials.index');
        Route::post('/credentials/{credential}/attachments', [CredentialAttachmentController::class, 'store'])->name('credentials.attachments.store');
        Route::delete('/credentials/{credential}/attachments/{kind}/{attachment}', [CredentialAttachmentController::class, 'destroy'])
            ->whereIn('kind', ['identifier', 'profile'])
            ->name('credentials.attachments.destroy');
        Route::post('/apps', [MobileAppController::class, 'store'])->name('apps.store');
        Route::get('/apps/{mobileApp:slug}', [MobileAppController::class, 'show'])->name('apps.show');
        Route::patch('/apps/{mobileApp:slug}', [MobileAppController::class, 'update'])->name('apps.update');
        Route::delete('/apps/{mobileApp:slug}', [MobileAppController::class, 'destroy'])->name('apps.destroy');
        Route::patch('/apps/{mobileApp:slug}/builds/{buildRecord}/cancel', [BuildLifecycleController::class, 'cancel'])
            ->name('apps.builds.cancel');
        Route::get('/apps/{mobileApp:slug}/builds/{buildRecord}/logs', [ApiBuildLogController::class, 'index'])
            ->name('apps.builds.logs.index');
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

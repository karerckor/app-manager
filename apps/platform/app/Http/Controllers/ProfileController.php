<?php

namespace App\Http\Controllers;

use App\Models\Team;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Inertia\Inertia;
use Inertia\Response;
use Laravel\Fortify\Fortify;

class ProfileController extends Controller
{
    public function show(Request $request): Response
    {
        $user = $request->user();
        $passwordConfirmed = (time() - $request->session()->get('auth.password_confirmed_at', 0))
            < config('auth.password_timeout', 10800);
        $hasTwoFactorSecret = ! is_null($user->two_factor_secret);
        $twoFactorEnabled = ! is_null($user->two_factor_confirmed_at);
        $twoFactorPending = $hasTwoFactorSecret && ! $twoFactorEnabled;

        return Inertia::render('Profile/Edit', [
            'twoFactor' => [
                'enabled' => $twoFactorEnabled,
                'pending' => $twoFactorPending,
                'passwordConfirmed' => $passwordConfirmed,
                'qrCode' => $passwordConfirmed && $twoFactorPending
                    ? $user->twoFactorQrCodeSvg()
                    : null,
                'setupKey' => $passwordConfirmed && $twoFactorPending
                    ? Fortify::currentEncrypter()->decrypt($user->two_factor_secret)
                    : null,
            ],
            'ownedTeamsCount' => Team::query()
                ->where('owner_id', $user->id)
                ->count(),
        ]);
    }

    public function recoveryCodes(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'password' => ['required', 'current_password:web'],
        ]);

        if ($validator->fails()) {
            return response()
                ->json([
                    'message' => 'The given data was invalid.',
                    'errors' => $validator->errors(),
                ], 422)
                ->header('Cache-Control', 'no-store, private');
        }

        $user = $request->user();

        abort_unless(
            $user->two_factor_confirmed_at
                && $user->two_factor_secret
                && $user->two_factor_recovery_codes,
            404,
        );

        $request->session()->put('auth.password_confirmed_at', now()->timestamp);

        return response()
            ->json(['codes' => $user->recoveryCodes()])
            ->header('Cache-Control', 'no-store, private');
    }

    public function destroy(Request $request): RedirectResponse
    {
        $request->validateWithBag('deleteAccount', [
            'password' => ['required', 'current_password:web'],
        ]);

        $user = $request->user();

        DB::transaction(function () use ($user) {
            Team::query()
                ->where('owner_id', $user->id)
                ->get()
                ->each
                ->delete();

            $user->tokens()->delete();

            DB::table('password_reset_tokens')
                ->where('email', $user->email)
                ->delete();

            DB::table('sessions')
                ->where('user_id', $user->id)
                ->delete();

            $user->delete();
        });

        Auth::guard('web')->logoutCurrentDevice();
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return redirect()
            ->route('login')
            ->with('status', 'Your account has been deleted.');
    }
}

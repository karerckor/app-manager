<?php

namespace App\Http\Controllers;

use App\Enums\Platform;
use App\Models\AppIdentifier;
use App\Models\MobileApp;
use App\Models\Team;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class AppIdentifierController extends Controller
{
    public function store(Request $request, Team $team, MobileApp $mobileApp): RedirectResponse
    {
        $this->authorize('manageApplications', $team);

        $data = $request->validate($this->rules($request, $mobileApp));

        DB::transaction(function () use ($mobileApp, $data): void {
            $platform = Platform::from($data['platform']);

            if (! array_key_exists('is_default', $data)) {
                $data['is_default'] = ! $mobileApp->appIdentifiers()
                    ->where('platform', $platform->value)
                    ->exists();
            }

            if ($data['is_default']) {
                $this->clearDefault($mobileApp, $platform);
            }

            $mobileApp->appIdentifiers()->create($data);
        });

        return back()->with('status', 'App identifier added.');
    }

    public function update(
        Request $request,
        Team $team,
        MobileApp $mobileApp,
        AppIdentifier $appIdentifier,
    ): RedirectResponse {
        $this->authorize('manageApplications', $team);

        $data = $request->validate($this->rules($request, $mobileApp, $appIdentifier));
        $destinationPlatform = Platform::from($data['platform']);
        $willBeDefault = $data['is_default'] ?? $appIdentifier->is_default;

        if (
            $destinationPlatform !== $appIdentifier->platform
            && $willBeDefault
            && $mobileApp->appIdentifiers()
                ->where('platform', $destinationPlatform->value)
                ->where('is_default', true)
                ->whereKeyNot($appIdentifier->id)
                ->exists()
        ) {
            throw ValidationException::withMessages([
                'is_default' => 'The destination platform already has a default identifier.',
            ]);
        }

        DB::transaction(function () use ($mobileApp, $appIdentifier, $data, $destinationPlatform): void {
            if (($data['is_default'] ?? false) === true) {
                $this->clearDefault($mobileApp, $destinationPlatform, $appIdentifier);
            }

            $appIdentifier->update($data);
        });

        return back()->with('status', 'App identifier updated.');
    }

    private function rules(
        Request $request,
        MobileApp $mobileApp,
        ?AppIdentifier $appIdentifier = null,
    ): array {
        return [
            'platform' => ['required', Rule::enum(Platform::class)],
            'identifier' => [
                'required',
                'string',
                'max:255',
                'regex:/^[A-Za-z0-9]+(?:[._-][A-Za-z0-9]+)+$/',
                Rule::unique('app_identifiers')->where(fn ($query) => $query
                    ->where('mobile_app_id', $mobileApp->id)
                    ->where('platform', $request->input('platform')))->ignore($appIdentifier?->id),
            ],
            'name' => ['nullable', 'string', 'max:255'],
            'version' => ['nullable', 'string', 'max:255'],
            'version_code' => [
                'nullable',
                Rule::prohibitedIf($request->input('platform') !== Platform::Android->value),
                'integer',
                'min:1',
            ],
            'build_number' => [
                'nullable',
                Rule::prohibitedIf($request->input('platform') !== Platform::Ios->value),
                'string',
                'max:255',
            ],
            'is_default' => ['boolean'],
        ];
    }

    private function clearDefault(
        MobileApp $mobileApp,
        Platform $platform,
        ?AppIdentifier $except = null,
    ): void {
        $query = $mobileApp->appIdentifiers()
            ->where('platform', $platform->value)
            ->where('is_default', true);

        if ($except) {
            $query->whereKeyNot($except->id);
        }

        $query->update(['is_default' => false]);
    }
}

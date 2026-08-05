<?php

namespace App\Http\Controllers\Api\V1;

use App\Enums\Platform;
use App\Http\Controllers\Controller;
use App\Http\Resources\Api\V1\AppIdentifierResource;
use App\Models\AppIdentifier;
use App\Models\MobileApp;
use App\Models\Team;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class AppIdentifierController extends Controller
{
    public function index(Team $team, MobileApp $mobileApp): AnonymousResourceCollection
    {
        return AppIdentifierResource::collection(
            $mobileApp->appIdentifiers()
                ->orderBy('platform')
                ->orderByDesc('is_default')
                ->orderBy('name')
                ->orderBy('id')
                ->get(),
        );
    }

    public function store(Request $request, Team $team, MobileApp $mobileApp): JsonResponse
    {
        $this->authorize('manageApplications', $team);

        $data = $request->validate($this->rules($request, $mobileApp));
        $appIdentifier = DB::transaction(function () use ($mobileApp, $data) {
            $platform = Platform::from($data['platform']);

            if (! array_key_exists('is_default', $data)) {
                $data['is_default'] = ! $mobileApp->appIdentifiers()
                    ->where('platform', $platform)
                    ->exists();
            }

            if ($data['is_default']) {
                $this->clearDefault($mobileApp, $platform);
            }

            return $mobileApp->appIdentifiers()->create($data);
        });

        return (new AppIdentifierResource($appIdentifier))
            ->response()
            ->setStatusCode(201);
    }

    public function show(Team $team, MobileApp $mobileApp, AppIdentifier $appIdentifier): AppIdentifierResource
    {
        return new AppIdentifierResource($appIdentifier);
    }

    public function update(
        Request $request,
        Team $team,
        MobileApp $mobileApp,
        AppIdentifier $appIdentifier,
    ): AppIdentifierResource {
        $this->authorize('manageApplications', $team);

        $data = $request->validate($this->rules($request, $mobileApp, $appIdentifier));
        $destinationPlatform = Platform::from($data['platform'] ?? $appIdentifier->platform->value);
        $willBeDefault = $data['is_default'] ?? $appIdentifier->is_default;

        if (
            $destinationPlatform !== $appIdentifier->platform
            && $willBeDefault
            && $mobileApp->appIdentifiers()
                ->where('platform', $destinationPlatform)
                ->where('is_default', true)
                ->whereKeyNot($appIdentifier->id)
                ->exists()
        ) {
            throw ValidationException::withMessages([
                'is_default' => 'The destination platform already has a default identifier.',
            ]);
        }

        DB::transaction(function () use ($mobileApp, $appIdentifier, $data, $destinationPlatform) {
            if (($data['is_default'] ?? false) === true) {
                $this->clearDefault($mobileApp, $destinationPlatform, $appIdentifier);
            }

            $appIdentifier->update($data);
        });

        return new AppIdentifierResource($appIdentifier->refresh());
    }

    public function destroy(Team $team, MobileApp $mobileApp, AppIdentifier $appIdentifier): Response
    {
        $this->authorize('manageApplications', $team);

        $appIdentifier->delete();

        return response()->noContent();
    }

    private function rules(
        Request $request,
        MobileApp $mobileApp,
        ?AppIdentifier $appIdentifier = null,
    ): array {
        $required = $appIdentifier ? 'sometimes' : 'required';
        $platform = $request->input('platform', $appIdentifier?->platform->value);

        return [
            'platform' => [$required, Rule::enum(Platform::class)],
            'identifier' => [
                $required,
                'string',
                'max:255',
                'regex:/^[A-Za-z0-9]+(?:[._-][A-Za-z0-9]+)+$/',
                Rule::unique('app_identifiers')->where(fn ($query) => $query
                    ->where('mobile_app_id', $mobileApp->id)
                    ->where('platform', $platform))->ignore($appIdentifier?->id),
            ],
            'name' => ['sometimes', 'nullable', 'string', 'max:255'],
            'version' => ['sometimes', 'nullable', 'string', 'max:255'],
            'version_code' => [
                'sometimes',
                'nullable',
                Rule::prohibitedIf($platform !== Platform::Android->value),
                'integer',
                'min:1',
            ],
            'build_number' => [
                'sometimes',
                'nullable',
                Rule::prohibitedIf($platform !== Platform::Ios->value),
                'string',
                'max:255',
            ],
            'is_default' => ['sometimes', 'boolean'],
        ];
    }

    private function clearDefault(
        MobileApp $mobileApp,
        Platform $platform,
        ?AppIdentifier $except = null,
    ): void {
        $query = $mobileApp->appIdentifiers()
            ->where('platform', $platform)
            ->where('is_default', true);

        if ($except) {
            $query->whereKeyNot($except->id);
        }

        $query->update(['is_default' => false]);
    }
}

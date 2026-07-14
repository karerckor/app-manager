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
use Illuminate\Validation\Rule;

class AppIdentifierController extends Controller
{
    public function index(Team $team, MobileApp $mobileApp): AnonymousResourceCollection
    {
        return AppIdentifierResource::collection(
            $mobileApp->appIdentifiers()->orderBy('platform')->orderBy('name')->get(),
        );
    }

    public function store(Request $request, Team $team, MobileApp $mobileApp): JsonResponse
    {
        $data = $request->validate($this->rules($request, $mobileApp));
        $appIdentifier = $mobileApp->appIdentifiers()->create($data);

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
        $data = $request->validate($this->rules($request, $mobileApp, $appIdentifier));
        $appIdentifier->update($data);

        return new AppIdentifierResource($appIdentifier->refresh());
    }

    public function destroy(Team $team, MobileApp $mobileApp, AppIdentifier $appIdentifier): Response
    {
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
        ];
    }
}

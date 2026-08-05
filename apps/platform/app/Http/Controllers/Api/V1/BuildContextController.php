<?php

namespace App\Http\Controllers\Api\V1;

use App\Enums\Platform;
use App\Enums\ProfileType;
use App\Http\Controllers\Controller;
use App\Http\Resources\Api\V1\AppIdentifierResource;
use App\Http\Resources\Api\V1\CredentialResource;
use App\Http\Resources\Api\V1\MobileAppResource;
use App\Models\MobileApp;
use App\Models\Team;
use App\Support\Builds\BuildContextResolver;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Arr;
use Illuminate\Validation\Rule;

class BuildContextController extends Controller
{
    public function __invoke(
        Request $request,
        Team $team,
        MobileApp $mobileApp,
        BuildContextResolver $resolver,
    ): JsonResponse {
        $data = $request->validate([
            'platform' => ['required', Rule::enum(Platform::class)],
            'profile_type' => ['required', Rule::enum(ProfileType::class)],
            'identifier' => ['sometimes', 'string', 'max:255'],
        ]);

        $context = $resolver->resolve(
            $mobileApp,
            Platform::from($data['platform']),
            ProfileType::from($data['profile_type']),
            $data['identifier'] ?? null,
        );
        $app = (new MobileAppResource($mobileApp))->resolve($request);
        $profile = $context['profile'];

        return response()->json([
            'data' => [
                'schema_version' => 1,
                'app' => Arr::only($app, ['id', 'slug', 'name', 'description']),
                'target' => (new AppIdentifierResource($context['target']))->resolve($request),
                'profile' => [
                    ...$context['profile_definition'],
                    'id' => $profile?->id,
                    'name' => $profile?->name,
                ],
                'assets' => $app['assets'],
                'credentials' => CredentialResource::collection($context['credentials'])
                    ->resolve($request),
            ],
        ]);
    }
}

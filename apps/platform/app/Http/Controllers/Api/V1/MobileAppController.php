<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Resources\Api\V1\MobileAppResource;
use App\Models\MobileApp;
use App\Models\Team;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

class MobileAppController extends Controller
{
    public function index(Team $team): AnonymousResourceCollection
    {
        return MobileAppResource::collection($team->mobileApps()->latest()->get());
    }

    public function store(Request $request, Team $team): JsonResponse
    {
        $data = $request->validate($this->rules($team));
        $data['slug'] = $this->availableSlug($team, ($data['slug'] ?? null) ?: $data['name']);

        $mobileApp = $team->mobileApps()->create($data);

        return (new MobileAppResource($mobileApp))
            ->response()
            ->setStatusCode(201);
    }

    public function show(Team $team, MobileApp $mobileApp): MobileAppResource
    {
        return new MobileAppResource($mobileApp);
    }

    public function update(Request $request, Team $team, MobileApp $mobileApp): MobileAppResource
    {
        $data = $request->validate($this->rules($team, $mobileApp));
        $mobileApp->update($data);

        return new MobileAppResource($mobileApp->refresh());
    }

    public function destroy(Team $team, MobileApp $mobileApp): Response
    {
        $mobileApp->delete();

        return response()->noContent();
    }

    private function rules(Team $team, ?MobileApp $mobileApp = null): array
    {
        $required = $mobileApp ? 'sometimes' : 'required';

        return [
            'name' => [$required, 'string', 'max:255'],
            'slug' => [
                'sometimes',
                'string',
                'max:255',
                'alpha_dash:ascii',
                Rule::unique('mobile_apps')->where('team_id', $team->id)->ignore($mobileApp?->id),
            ],
            'description' => ['sometimes', 'nullable', 'string', 'max:2000'],
        ];
    }

    private function availableSlug(Team $team, string $value): string
    {
        $baseSlug = Str::slug($value) ?: 'app';
        $slug = $baseSlug;
        $suffix = 2;

        while ($team->mobileApps()->where('slug', $slug)->exists()) {
            $slug = $baseSlug.'-'.$suffix++;
        }

        return $slug;
    }
}

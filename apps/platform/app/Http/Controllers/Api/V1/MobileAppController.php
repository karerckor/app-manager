<?php

namespace App\Http\Controllers\Api\V1;

use App\Enums\WebhookEventType;
use App\Http\Concerns\ResolvesPageSize;
use App\Http\Controllers\Controller;
use App\Http\Resources\Api\V1\MobileAppResource;
use App\Integrations\Webhooks\WebhookEventRecorder;
use App\Integrations\Webhooks\WebhookPayload;
use App\Models\MobileApp;
use App\Models\Team;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

class MobileAppController extends Controller
{
    use ResolvesPageSize;

    public function __construct(private readonly WebhookEventRecorder $webhookEvents) {}

    public function index(Request $request, Team $team): AnonymousResourceCollection
    {
        return MobileAppResource::collection(
            $team->mobileApps()->latest()->paginate($this->perPage($request)),
        );
    }

    public function store(Request $request, Team $team): JsonResponse
    {
        $data = $request->validate($this->rules($team));
        $data['slug'] = $this->availableSlug($team, ($data['slug'] ?? null) ?: $data['name']);

        $mobileApp = DB::transaction(function () use ($team, $data) {
            $mobileApp = $team->mobileApps()->create($data);
            $this->webhookEvents->record(
                $team,
                WebhookEventType::MobileAppCreated,
                $mobileApp,
                WebhookPayload::mobileApp($mobileApp),
            );

            return $mobileApp;
        });

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
        DB::transaction(function () use ($mobileApp, $team, $data) {
            $mobileApp->update($data);
            $this->webhookEvents->record(
                $team,
                WebhookEventType::MobileAppUpdated,
                $mobileApp,
                WebhookPayload::mobileApp($mobileApp),
            );
        });

        return new MobileAppResource($mobileApp->refresh());
    }

    public function destroy(Request $request, Team $team, MobileApp $mobileApp): Response
    {
        DB::transaction(function () use ($mobileApp, $team) {
            $payload = WebhookPayload::mobileApp($mobileApp);
            $mobileApp->delete();
            $this->webhookEvents->record(
                $team,
                WebhookEventType::MobileAppDeleted,
                $mobileApp,
                $payload,
            );
        });

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

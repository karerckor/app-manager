<?php

namespace App\Http\Controllers\Api\V1;

use App\Enums\WebhookEventType;
use App\Http\Concerns\ResolvesPageSize;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\StoreMobileAppRequest;
use App\Http\Requests\Api\V1\UpdateMobileAppRequest;
use App\Http\Resources\Api\V1\MobileAppResource;
use App\Integrations\Webhooks\WebhookEventRecorder;
use App\Integrations\Webhooks\WebhookPayload;
use App\Models\MobileApp;
use App\Models\Team;
use App\Support\Blueprints\BlueprintComplianceEvaluator;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class MobileAppController extends Controller
{
    use ResolvesPageSize;

    public function __construct(
        private readonly WebhookEventRecorder $webhookEvents,
        private readonly BlueprintComplianceEvaluator $blueprints,
    ) {}

    public function index(Request $request, Team $team): AnonymousResourceCollection
    {
        return MobileAppResource::collection(
            $team->mobileApps()->latest()->paginate($this->perPage($request)),
        );
    }

    public function trash(Request $request, Team $team): AnonymousResourceCollection
    {
        return MobileAppResource::collection(
            $team->mobileApps()->onlyTrashed()->latest('deleted_at')->paginate($this->perPage($request)),
        );
    }

    public function store(StoreMobileAppRequest $request, Team $team): JsonResponse
    {
        $data = $request->validated();
        $data['slug'] = $this->availableSlug($team, ($data['slug'] ?? null) ?: $data['name']);

        $mobileApp = DB::transaction(function () use ($team, $data) {
            $mobileApp = $team->mobileApps()->create($data);
            $this->blueprints->evaluate($mobileApp);
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

    public function update(UpdateMobileAppRequest $request, Team $team, MobileApp $mobileApp): MobileAppResource
    {
        DB::transaction(function () use ($mobileApp, $team, $request) {
            $mobileApp->update($request->validated());
            $mobileApp->unsetRelation('blueprint');
            $this->blueprints->evaluate($mobileApp);
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

    public function restore(Team $team, string $mobileApp): MobileAppResource
    {
        $app = $team->mobileApps()->onlyTrashed()->whereKey($mobileApp)->firstOrFail();
        $app->restore();

        return new MobileAppResource($app->refresh());
    }

    private function availableSlug(Team $team, string $value): string
    {
        $baseSlug = Str::slug($value) ?: 'app';
        $slug = $baseSlug;
        $suffix = 2;

        while ($team->mobileApps()->withTrashed()->where('slug', $slug)->exists()) {
            $slug = $baseSlug.'-'.$suffix++;
        }

        return $slug;
    }
}

<?php

namespace App\Http\Controllers\Api\V1;

use App\Enums\WebhookEventType;
use App\Http\Concerns\ResolvesPageSize;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\StoreAppIdentifierRequest;
use App\Http\Requests\Api\V1\UpdateAppIdentifierRequest;
use App\Http\Resources\Api\V1\AppIdentifierResource;
use App\Integrations\Webhooks\WebhookEventRecorder;
use App\Integrations\Webhooks\WebhookPayload;
use App\Models\AppIdentifier;
use App\Models\MobileApp;
use App\Models\Team;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\DB;

class AppIdentifierController extends Controller
{
    use ResolvesPageSize;

    public function __construct(private readonly WebhookEventRecorder $webhookEvents) {}

    public function index(Request $request, Team $team, MobileApp $mobileApp): AnonymousResourceCollection
    {
        return AppIdentifierResource::collection(
            $mobileApp->appIdentifiers()->orderBy('platform')->orderBy('name')->paginate($this->perPage($request)),
        );
    }

    public function store(StoreAppIdentifierRequest $request, Team $team, MobileApp $mobileApp): JsonResponse
    {
        $appIdentifier = DB::transaction(function () use ($mobileApp, $team, $request) {
            $appIdentifier = $mobileApp->appIdentifiers()->create($request->validated());
            $this->webhookEvents->record(
                $team,
                WebhookEventType::AppIdentifierCreated,
                $appIdentifier,
                WebhookPayload::appIdentifier($appIdentifier),
            );

            return $appIdentifier;
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
        UpdateAppIdentifierRequest $request,
        Team $team,
        MobileApp $mobileApp,
        AppIdentifier $appIdentifier,
    ): AppIdentifierResource {
        DB::transaction(function () use ($appIdentifier, $team, $request) {
            $appIdentifier->update($request->validated());
            $this->webhookEvents->record(
                $team,
                WebhookEventType::AppIdentifierUpdated,
                $appIdentifier,
                WebhookPayload::appIdentifier($appIdentifier),
            );
        });

        return new AppIdentifierResource($appIdentifier->refresh());
    }

    public function destroy(
        Request $request,
        Team $team,
        MobileApp $mobileApp,
        AppIdentifier $appIdentifier,
    ): Response {
        DB::transaction(function () use ($appIdentifier, $team) {
            $payload = WebhookPayload::appIdentifier($appIdentifier);
            $appIdentifier->delete();
            $this->webhookEvents->record(
                $team,
                WebhookEventType::AppIdentifierDeleted,
                $appIdentifier,
                $payload,
            );
        });

        return response()->noContent();
    }
}

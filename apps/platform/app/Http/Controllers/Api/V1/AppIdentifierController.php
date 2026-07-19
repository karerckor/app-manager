<?php

namespace App\Http\Controllers\Api\V1;

use App\Enums\Platform;
use App\Enums\WebhookEventType;
use App\Http\Concerns\ResolvesPageSize;
use App\Http\Controllers\Controller;
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
use Illuminate\Validation\Rule;

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

    public function store(Request $request, Team $team, MobileApp $mobileApp): JsonResponse
    {
        $data = $request->validate($this->rules($request, $mobileApp));
        $appIdentifier = DB::transaction(function () use ($mobileApp, $team, $request, $data) {
            $appIdentifier = $mobileApp->appIdentifiers()->create($data);
            $this->webhookEvents->record(
                $team,
                WebhookEventType::AppIdentifierCreated,
                $appIdentifier,
                WebhookPayload::appIdentifier($appIdentifier),
                $request->user(),
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
        Request $request,
        Team $team,
        MobileApp $mobileApp,
        AppIdentifier $appIdentifier,
    ): AppIdentifierResource {
        $data = $request->validate($this->rules($request, $mobileApp, $appIdentifier));
        DB::transaction(function () use ($appIdentifier, $team, $request, $data) {
            $appIdentifier->update($data);
            $this->webhookEvents->record(
                $team,
                WebhookEventType::AppIdentifierUpdated,
                $appIdentifier,
                WebhookPayload::appIdentifier($appIdentifier),
                $request->user(),
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
        DB::transaction(function () use ($appIdentifier, $team, $request) {
            $payload = WebhookPayload::appIdentifier($appIdentifier);
            $appIdentifier->delete();
            $this->webhookEvents->record(
                $team,
                WebhookEventType::AppIdentifierDeleted,
                $appIdentifier,
                $payload,
                $request->user(),
            );
        });

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

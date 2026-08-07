<?php

namespace App\Http\Controllers;

use App\Enums\WebhookEventType;
use App\Http\Requests\StoreAppIdentifierRequest;
use App\Http\Requests\UpdateAppIdentifierRequest;
use App\Integrations\Webhooks\WebhookEventRecorder;
use App\Integrations\Webhooks\WebhookPayload;
use App\Models\AppIdentifier;
use App\Models\MobileApp;
use App\Models\Team;
use App\Support\Blueprints\BlueprintComplianceEvaluator;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class AppIdentifierController extends Controller
{
    public function __construct(
        private readonly WebhookEventRecorder $webhookEvents,
        private readonly BlueprintComplianceEvaluator $blueprints,
    ) {}

    public function store(StoreAppIdentifierRequest $request, Team $team, MobileApp $mobileApp): RedirectResponse
    {
        DB::transaction(function () use ($mobileApp, $team, $request) {
            $identifier = $mobileApp->appIdentifiers()->create($request->validated());
            $this->blueprints->evaluate($mobileApp);
            $this->webhookEvents->record(
                $team,
                WebhookEventType::AppIdentifierCreated,
                $identifier,
                WebhookPayload::appIdentifier($identifier),
            );
        });

        return back()->with('status', 'App identifier added.');
    }

    public function update(
        UpdateAppIdentifierRequest $request,
        Team $team,
        MobileApp $mobileApp,
        AppIdentifier $appIdentifier,
    ): RedirectResponse {
        DB::transaction(function () use ($appIdentifier, $mobileApp, $team, $request) {
            $appIdentifier->update($request->validated());
            $this->blueprints->evaluate($mobileApp);
            $this->webhookEvents->record(
                $team,
                WebhookEventType::AppIdentifierUpdated,
                $appIdentifier,
                WebhookPayload::appIdentifier($appIdentifier),
            );
        });

        return back()->with('status', 'App identifier updated.');
    }

    public function destroy(
        Request $request,
        Team $team,
        MobileApp $mobileApp,
        AppIdentifier $appIdentifier,
    ): RedirectResponse {
        DB::transaction(function () use ($appIdentifier, $mobileApp, $team) {
            $payload = WebhookPayload::appIdentifier($appIdentifier);
            $appIdentifier->delete();
            $this->blueprints->evaluate($mobileApp);
            $this->webhookEvents->record(
                $team,
                WebhookEventType::AppIdentifierDeleted,
                $appIdentifier,
                $payload,
            );
        });

        return back()->with('status', 'App identifier removed.');
    }

    public function restore(
        Request $request,
        Team $team,
        MobileApp $mobileApp,
        string $appIdentifier,
    ): RedirectResponse {
        $identifier = $mobileApp->appIdentifiers()->onlyTrashed()->whereKey($appIdentifier)->firstOrFail();
        $identifier->restore();
        $this->blueprints->evaluate($mobileApp);

        return back()->with('status', 'App identifier restored.');
    }
}

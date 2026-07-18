<?php

namespace App\Http\Controllers;

use App\Enums\Platform;
use App\Enums\WebhookEventType;
use App\Integrations\Webhooks\WebhookEventRecorder;
use App\Integrations\Webhooks\WebhookPayload;
use App\Models\AppIdentifier;
use App\Models\MobileApp;
use App\Models\Team;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

class AppIdentifierController extends Controller
{
    public function __construct(private readonly WebhookEventRecorder $webhookEvents) {}

    public function store(Request $request, Team $team, MobileApp $mobileApp): RedirectResponse
    {
        abort_unless($request->user()->teams()->whereKey($team->id)->exists(), 403);

        $data = $request->validate($this->rules($request, $mobileApp));

        DB::transaction(function () use ($mobileApp, $team, $request, $data) {
            $identifier = $mobileApp->appIdentifiers()->create($data);
            $this->webhookEvents->record(
                $team,
                WebhookEventType::AppIdentifierCreated,
                $identifier,
                WebhookPayload::appIdentifier($identifier),
                $request->user(),
            );
        });

        return back()->with('status', 'App identifier added.');
    }

    public function update(
        Request $request,
        Team $team,
        MobileApp $mobileApp,
        AppIdentifier $appIdentifier,
    ): RedirectResponse {
        abort_unless($request->user()->teams()->whereKey($team->id)->exists(), 403);

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
        ];
    }
}

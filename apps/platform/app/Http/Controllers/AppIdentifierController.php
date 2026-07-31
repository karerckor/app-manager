<?php

namespace App\Http\Controllers;

use App\Enums\Platform;
use App\Enums\WebhookEventType;
use App\Integrations\Webhooks\WebhookEventRecorder;
use App\Integrations\Webhooks\WebhookPayload;
use App\Models\AppIdentifier;
use App\Models\MobileApp;
use App\Models\Team;
use App\Rules\ValidDomainName;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class AppIdentifierController extends Controller
{
    public function __construct(private readonly WebhookEventRecorder $webhookEvents) {}

    public function store(Request $request, Team $team, MobileApp $mobileApp): RedirectResponse
    {
        abort_unless($request->user()->teams()->whereKey($team->id)->exists(), 403);

        $this->normalizeWebIdentifier($request);
        $data = $request->validate($this->rules($request, $mobileApp));

        DB::transaction(function () use ($mobileApp, $team, $data) {
            $identifier = $mobileApp->appIdentifiers()->create($data);
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
        Request $request,
        Team $team,
        MobileApp $mobileApp,
        AppIdentifier $appIdentifier,
    ): RedirectResponse {
        abort_unless($request->user()->teams()->whereKey($team->id)->exists(), 403);

        $this->normalizeWebIdentifier($request, $appIdentifier);
        $data = $request->validate($this->rules($request, $mobileApp, $appIdentifier));
        $this->ensureWebHasNoCredentials($appIdentifier, $data['platform']);
        DB::transaction(function () use ($appIdentifier, $team, $data) {
            $appIdentifier->update($data);
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
        abort_unless($request->user()->teams()->whereKey($team->id)->exists(), 403);

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

        return back()->with('status', 'App identifier removed.');
    }

    private function rules(
        Request $request,
        MobileApp $mobileApp,
        ?AppIdentifier $appIdentifier = null,
    ): array {
        $platform = $request->input('platform', $appIdentifier?->platform->value);

        return [
            'platform' => ['required', Rule::enum(Platform::class)],
            'identifier' => [
                'required',
                'string',
                'max:255',
                $platform === Platform::Web->value
                    ? new ValidDomainName
                    : 'regex:/^[A-Za-z0-9]+(?:[._-][A-Za-z0-9]+)+$/',
                Rule::unique('app_identifiers')->where(fn ($query) => $query
                    ->where('mobile_app_id', $mobileApp->id)
                    ->where('platform', $platform))->ignore($appIdentifier?->id),
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

    private function normalizeWebIdentifier(
        Request $request,
        ?AppIdentifier $appIdentifier = null,
    ): void {
        $platform = $request->input('platform', $appIdentifier?->platform->value);

        if ($platform === Platform::Web->value && is_string($request->input('identifier'))) {
            $request->merge([
                'identifier' => strtolower(trim($request->input('identifier'))),
            ]);
        }
    }

    private function ensureWebHasNoCredentials(
        AppIdentifier $appIdentifier,
        string $platform,
    ): void {
        if ($platform === Platform::Web->value
            && $appIdentifier->platform !== Platform::Web
            && ($appIdentifier->credentials()->exists() || $appIdentifier->profiles()->exists())) {
            throw ValidationException::withMessages([
                'platform' => 'Detach all credentials before changing this identifier to Web.',
            ]);
        }
    }
}

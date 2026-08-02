<?php

namespace App\Http\Controllers;

use App\Enums\WebhookEventType;
use App\Http\Requests\StoreMobileAppRequest;
use App\Http\Requests\UpdateMobileAppRequest;
use App\Integrations\Webhooks\WebhookEventRecorder;
use App\Integrations\Webhooks\WebhookPayload;
use App\Models\MobileApp;
use App\Models\Team;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class MobileAppController extends Controller
{
    public function __construct(private readonly WebhookEventRecorder $webhookEvents) {}

    public function store(StoreMobileAppRequest $request, Team $team): RedirectResponse
    {
        $data = $request->validated();
        $data['slug'] = $this->availableSlug($team, ($data['slug'] ?? null) ?: $data['name']);

        $app = DB::transaction(function () use ($team, $data) {
            $app = $team->mobileApps()->create($data);
            $this->webhookEvents->record(
                $team,
                WebhookEventType::MobileAppCreated,
                $app,
                WebhookPayload::mobileApp($app),
            );

            return $app;
        });

        return redirect()->route('accounts.apps.show', ['team' => $team, 'mobileApp' => $app])->with('status', 'Application created.');
    }

    public function update(UpdateMobileAppRequest $request, Team $team, MobileApp $mobileApp): RedirectResponse
    {
        DB::transaction(function () use ($mobileApp, $team, $request) {
            $attributes = [
                'name' => $request->validated('name'),
                'description' => $request->validated('description') ?? null,
            ];

            if ($request->has('metadata')) {
                $attributes['metadata'] = $request->validated('metadata');
            }

            $mobileApp->update($attributes);
            $this->webhookEvents->record(
                $team,
                WebhookEventType::MobileAppUpdated,
                $mobileApp,
                WebhookPayload::mobileApp($mobileApp),
            );
        });

        return redirect()
            ->route('accounts.apps.show', ['team' => $team, 'mobileApp' => $mobileApp])
            ->with('status', 'Application updated.');
    }

    public function destroy(Request $request, Team $team, MobileApp $mobileApp): RedirectResponse
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

        return redirect()
            ->route('accounts.apps.index', ['team' => $team])
            ->with('status', 'Application deleted.');
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

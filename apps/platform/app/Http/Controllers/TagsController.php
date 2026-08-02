<?php

namespace App\Http\Controllers;

use App\Enums\WebhookEventType;
use App\Integrations\Webhooks\WebhookEventRecorder;
use App\Integrations\Webhooks\WebhookPayload;
use App\Models\Artifact;
use App\Models\BuildRecord;
use App\Models\Credential;
use App\Models\MobileApp;
use App\Models\Team;
use App\Support\Tags\TagRules;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class TagsController extends Controller
{
    public function __construct(private readonly WebhookEventRecorder $webhookEvents) {}

    public function updateApp(Request $request, Team $team, MobileApp $mobileApp): RedirectResponse
    {
        abort_unless($mobileApp->team_id === $team->id, 404);

        $this->sync($request, $team, $mobileApp);

        return back()->with('status', 'Tags updated.');
    }

    public function updateCredential(Request $request, Team $team, Credential $credential): RedirectResponse
    {
        $this->sync($request, $team, $credential);

        return back()->with('status', 'Tags updated.');
    }

    public function updateBuild(Request $request, Team $team, MobileApp $mobileApp, BuildRecord $buildRecord): RedirectResponse
    {
        abort_unless($mobileApp->team_id === $team->id, 404);
        abort_unless($buildRecord->mobile_app_id === $mobileApp->id && $buildRecord->team_id === $team->id, 404);

        $this->sync($request, $team, $buildRecord);

        return back()->with('status', 'Tags updated.');
    }

    public function updateArtifact(Request $request, Team $team, MobileApp $mobileApp, BuildRecord $buildRecord, Artifact $artifact): RedirectResponse
    {
        abort_unless($mobileApp->team_id === $team->id, 404);
        abort_unless($buildRecord->mobile_app_id === $mobileApp->id && $buildRecord->team_id === $team->id, 404);
        abort_unless($artifact->build_record_id === $buildRecord->id && $artifact->team_id === $team->id, 404);

        $this->sync($request, $team, $artifact);

        return back()->with('status', 'Tags updated.');
    }

    private function sync(Request $request, Team $team, Model $model): void
    {
        $data = $request->validate(TagRules::rules());

        DB::transaction(function () use ($model, $team, $data) {
            $model->syncTags($data['tags'] ?? [])->save();

            [$event, $payload] = match (true) {
                $model instanceof MobileApp => [WebhookEventType::MobileAppUpdated, WebhookPayload::mobileApp($model)],
                $model instanceof Credential => [WebhookEventType::CredentialUpdated, WebhookPayload::credential($model)],
                default => [null, null],
            };

            if ($event !== null) {
                $this->webhookEvents->record($team, $event, $model, $payload);
            }
        });
    }
}

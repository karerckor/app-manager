<?php

namespace App\Http\Controllers;

use App\Enums\TeamPermission;
use App\Enums\WebhookEventType;
use App\Http\Concerns\NormalizesArtifactMetadata;
use App\Integrations\Webhooks\WebhookEventRecorder;
use App\Integrations\Webhooks\WebhookPayload;
use App\Models\BuildRecord;
use App\Models\MobileApp;
use App\Models\Team;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

class BuildArtifactController extends Controller
{
    use NormalizesArtifactMetadata;

    public function __construct(private readonly WebhookEventRecorder $webhookEvents) {}

    public function store(Request $request, Team $team, MobileApp $mobileApp, BuildRecord $buildRecord): RedirectResponse
    {
        $this->assertCanManage($request, $team, $mobileApp, $buildRecord);

        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'file' => ['required', 'file', 'max:512000'],
            'metadata' => ['nullable'],
        ]);

        DB::transaction(function () use ($buildRecord, $team, $request, $data) {
            $artifact = $buildRecord->artifacts()->create([
                'team_id' => $team->id,
                'name' => $data['name'],
                'metadata' => $this->normalizeMetadata($request),
            ]);
            $artifact->addMediaFromRequest('file')->toMediaCollection('file');

            $this->webhookEvents->record(
                $team,
                WebhookEventType::ArtifactCreated,
                $artifact,
                WebhookPayload::artifact($artifact),
                $request->user(),
            );
        });

        return back()->with('status', 'Artifact uploaded.');
    }

    public function destroy(Request $request, Team $team, MobileApp $mobileApp, BuildRecord $buildRecord, string $artifact): RedirectResponse
    {
        $this->assertCanManage($request, $team, $mobileApp, $buildRecord);
        $artifact = $buildRecord->artifacts()->whereKey($artifact)->firstOrFail();

        DB::transaction(function () use ($artifact, $team, $request) {
            $payload = WebhookPayload::artifact($artifact);
            $artifact->delete();
            $this->webhookEvents->record(
                $team,
                WebhookEventType::ArtifactDeleted,
                $artifact,
                $payload,
                $request->user(),
            );
        });

        return back()->with('status', 'Artifact deleted.');
    }

    public function download(Request $request, Team $team, MobileApp $mobileApp, BuildRecord $buildRecord, string $artifact): BinaryFileResponse
    {
        abort_unless($request->user()->teams()->whereKey($team->id)->exists(), 403);
        $this->assertBuildBelongs($team, $mobileApp, $buildRecord);
        $media = $buildRecord->artifacts()->whereKey($artifact)->firstOrFail()->getFirstMedia('file');
        abort_unless($media, 404);

        return response()->download($media->getPath(), $media->file_name);
    }

    private function assertBuildBelongs(Team $team, MobileApp $mobileApp, BuildRecord $buildRecord): void
    {
        abort_unless($mobileApp->team_id === $team->id, 404);
        abort_unless($buildRecord->mobile_app_id === $mobileApp->id && $buildRecord->team_id === $team->id, 404);
    }

    private function assertCanManage(Request $request, Team $team, MobileApp $mobileApp, BuildRecord $buildRecord): void
    {
        $this->assertBuildBelongs($team, $mobileApp, $buildRecord);
        abort_unless($team->allows($request->user(), TeamPermission::ManageApplications), 403);
    }
}

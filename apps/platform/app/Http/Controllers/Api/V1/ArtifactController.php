<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Concerns\ResolvesPageSize;
use App\Http\Controllers\Controller;
use App\Http\Resources\Api\V1\ArtifactResource;
use App\Integrations\Webhooks\WebhookEventRecorder;
use App\Models\Artifact;
use App\Models\BuildRecord;
use App\Models\MobileApp;
use App\Models\Team;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

class ArtifactController extends Controller
{
    use ResolvesPageSize;

    public function __construct(private readonly WebhookEventRecorder $webhookEvents) {}

    public function index(Request $request, Team $team, MobileApp $mobileApp, BuildRecord $buildRecord): AnonymousResourceCollection
    {
        $this->assertBuildBelongs($team, $mobileApp, $buildRecord);

        $artifacts = $buildRecord->artifacts()
            ->with('media')
            ->latest()
            ->paginate($this->perPage($request));

        return ArtifactResource::collection($artifacts);
    }

    public function show(Team $team, MobileApp $mobileApp, BuildRecord $buildRecord, string $artifact): ArtifactResource
    {
        return new ArtifactResource($this->artifact($team, $mobileApp, $buildRecord, $artifact));
    }

    public function download(Team $team, MobileApp $mobileApp, BuildRecord $buildRecord, string $artifact): BinaryFileResponse
    {
        $media = $this->artifact($team, $mobileApp, $buildRecord, $artifact)->getFirstMedia('file');
        abort_unless($media, 404);

        return response()->download($media->getPath(), $media->file_name);
    }

    protected function assertBuildBelongs(Team $team, MobileApp $mobileApp, BuildRecord $buildRecord): void
    {
        abort_unless($mobileApp->team_id === $team->id, 404);
        abort_unless($buildRecord->mobile_app_id === $mobileApp->id && $buildRecord->team_id === $team->id, 404);
    }

    protected function artifact(Team $team, MobileApp $mobileApp, BuildRecord $buildRecord, string $id): Artifact
    {
        $this->assertBuildBelongs($team, $mobileApp, $buildRecord);

        return $buildRecord->artifacts()
            ->with('media')
            ->whereKey($id)
            ->firstOrFail();
    }
}

<?php

namespace App\Http\Controllers\Api\V1;

use App\Actions\Builds\AttachEasBuild;
use App\Actions\Builds\CreateEasBuild;
use App\Actions\Builds\CreateManualBuild;
use App\Actions\Integrations\RequestPipelineBuildCancellation;
use App\Enums\BuildReportingMode;
use App\Enums\BuildStatus;
use App\Enums\ProfileType;
use App\Exceptions\InvalidBuildTransition;
use App\Http\Concerns\ResolvesPageSize;
use App\Http\Controllers\Controller;
use App\Http\Resources\Api\V1\BuildRecordResource;
use App\Jobs\Integrations\ReconcileEasBuild;
use App\Models\AppIdentifier;
use App\Models\BuildRecord;
use App\Models\MobileApp;
use App\Models\Team;
use App\Support\Builds\BuildLifecycle;
use App\Support\Tags\TagRules;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Validation\Rule;

class BuildController extends Controller
{
    use ResolvesPageSize;

    public function index(Request $request, Team $team, MobileApp $mobileApp): AnonymousResourceCollection
    {
        $this->assertApp($team, $mobileApp);

        return BuildRecordResource::collection(
            $mobileApp->buildRecords()->with('ciCdRun')->latest()->paginate($this->perPage($request, default: 50)),
        );
    }

    public function store(
        Request $request,
        Team $team,
        MobileApp $mobileApp,
        CreateManualBuild $createManual,
        CreateEasBuild $createEas,
    ): JsonResponse {
        $this->assertApp($team, $mobileApp);
        $data = $request->validate([
            'request_uuid' => ['required', 'uuid'],
            'profile_type' => ['required', Rule::enum(ProfileType::class)],
            'reporting_mode' => ['sometimes', Rule::in(['manual', 'eas'])],
            'status' => [
                'sometimes',
                Rule::in([
                    BuildStatus::Pending->value,
                    BuildStatus::Completed->value,
                    BuildStatus::Failed->value,
                ]),
                Rule::prohibitedIf(fn () => $request->input('reporting_mode', 'manual') !== 'manual'),
            ],
            'expo_build_id' => ['required_if:reporting_mode,eas', 'nullable', 'string'],
            'app_identifier_id' => ['nullable', 'uuid'],
            'context' => ['sometimes', 'array'],
            'parameters' => [
                'sometimes',
                'array',
                function (string $attribute, mixed $value, \Closure $fail): void {
                    if (strlen(json_encode($value, JSON_THROW_ON_ERROR)) > 65535) {
                        $fail('Build parameters may not exceed 64 KB.');
                    }
                },
            ],
            ...TagRules::rules(),
        ]);

        $reportingMode = $data['reporting_mode'] ?? 'manual';
        $identifier = isset($data['app_identifier_id'])
            ? AppIdentifier::query()
                ->whereKey($data['app_identifier_id'])
                ->where('mobile_app_id', $mobileApp->id)
                ->firstOrFail()
            : null;

        if ($reportingMode === 'eas') {
            $build = $createEas->handle(
                $team,
                $mobileApp,
                $request->user(),
                $data['request_uuid'],
                $data['expo_build_id'],
                ProfileType::from($data['profile_type']),
                $identifier,
                $data['context'] ?? [],
                $data['parameters'] ?? [],
            );
        } else {
            $build = $createManual->handle(
                $team,
                $mobileApp,
                $request->user(),
                $data['request_uuid'],
                ProfileType::from($data['profile_type']),
                $identifier,
                $data['context'] ?? [],
                $data['parameters'] ?? [],
                status: BuildStatus::from($data['status'] ?? BuildStatus::Pending->value),
            );
        }

        if (array_key_exists('tags', $data)) {
            $build->syncTags($data['tags'] ?? [])->save();
        }

        return (new BuildRecordResource($build))->response()->setStatusCode(201);
    }

    public function show(Team $team, MobileApp $mobileApp, BuildRecord $buildRecord): BuildRecordResource
    {
        $this->assertBuild($team, $mobileApp, $buildRecord);

        return new BuildRecordResource($buildRecord);
    }

    public function update(
        Request $request,
        Team $team,
        MobileApp $mobileApp,
        BuildRecord $buildRecord,
        BuildLifecycle $lifecycle,
        AttachEasBuild $attachEasBuild,
    ): BuildRecordResource|JsonResponse {
        $this->assertBuild($team, $mobileApp, $buildRecord);
        $data = $request->validate([
            ...TagRules::rules(),
            'status' => [
                'required_without_all:tags,expo_build_id',
                Rule::prohibitedIf(fn () => $request->filled('expo_build_id')),
                Rule::in([
                    BuildStatus::Running->value,
                    BuildStatus::Completed->value,
                    BuildStatus::Failed->value,
                    BuildStatus::Cancelled->value,
                ]),
            ],
            'tags' => ['required_without_all:status,expo_build_id', 'array'],
            'expo_build_id' => ['required_without_all:status,tags', 'string', 'max:255'],
        ]);
        $build = $buildRecord;

        if (isset($data['expo_build_id'])) {
            $build = $attachEasBuild->handle($team, $mobileApp, $buildRecord, $data['expo_build_id']);
        }

        // Tags are metadata, so they stay writable on automatically reported
        // builds; only the lifecycle itself is off limits there.
        if (isset($data['status'])) {
            abort_unless($build->reporting_mode === BuildReportingMode::Manual, 409, 'This build is managed automatically.');
            $status = BuildStatus::from($data['status']);

            try {
                $build = $status === BuildStatus::Running
                    ? $lifecycle->start($build)
                    : $lifecycle->finish($build, $status);
            } catch (InvalidBuildTransition $exception) {
                return response()->json(['message' => $exception->getMessage()], 409);
            }

            if ($build->status !== $status) {
                return response()->json([
                    'message' => "Build cannot transition from {$build->status->value} to {$status->value}.",
                ], 409);
            }
        }

        if (array_key_exists('tags', $data)) {
            $build->syncTags($data['tags'] ?? [])->save();
        }

        return new BuildRecordResource($build);
    }

    public function cancel(
        Team $team,
        MobileApp $mobileApp,
        BuildRecord $buildRecord,
        RequestPipelineBuildCancellation $cancel,
    ): BuildRecordResource {
        $this->assertBuild($team, $mobileApp, $buildRecord);
        $build = $cancel->handle($buildRecord);

        return new BuildRecordResource($build->load('ciCdRun'));
    }

    public function syncEasStatus(
        Team $team,
        MobileApp $mobileApp,
        BuildRecord $buildRecord,
    ): BuildRecordResource {
        $this->assertBuild($team, $mobileApp, $buildRecord);
        abort_unless(
            $buildRecord->reporting_mode === BuildReportingMode::Eas,
            409,
            'This endpoint is only available for EAS builds.',
        );

        if (! $buildRecord->status->isTerminal()) {
            ReconcileEasBuild::dispatchSync($buildRecord->id);
            $buildRecord->refresh();
        }

        return new BuildRecordResource($buildRecord);
    }

    private function assertApp(Team $team, MobileApp $mobileApp): void
    {
        abort_unless($mobileApp->team_id === $team->id, 404);
    }

    private function assertBuild(Team $team, MobileApp $mobileApp, BuildRecord $build): void
    {
        $this->assertApp($team, $mobileApp);
        abort_unless(
            $build->team_id === $team->id && $build->mobile_app_id === $mobileApp->id,
            404,
        );
    }
}

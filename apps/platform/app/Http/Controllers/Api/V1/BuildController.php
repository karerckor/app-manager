<?php

namespace App\Http\Controllers\Api\V1;

use App\Actions\Builds\CreateManualBuild;
use App\Enums\BuildReportingMode;
use App\Enums\BuildStatus;
use App\Enums\ProfileType;
use App\Exceptions\InvalidBuildTransition;
use App\Http\Concerns\ResolvesPageSize;
use App\Http\Controllers\Controller;
use App\Http\Resources\Api\V1\BuildRecordResource;
use App\Models\AppIdentifier;
use App\Models\BuildRecord;
use App\Models\MobileApp;
use App\Models\Team;
use App\Support\Builds\BuildLifecycle;
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
            $mobileApp->buildRecords()->latest()->paginate($this->perPage($request, default: 50)),
        );
    }

    public function store(
        Request $request,
        Team $team,
        MobileApp $mobileApp,
        CreateManualBuild $create,
    ): JsonResponse {
        $this->assertApp($team, $mobileApp);
        $data = $request->validate([
            'request_uuid' => ['required', 'uuid'],
            'profile_type' => ['required', Rule::enum(ProfileType::class)],
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
        ]);
        $identifier = isset($data['app_identifier_id'])
            ? AppIdentifier::query()
                ->whereKey($data['app_identifier_id'])
                ->where('mobile_app_id', $mobileApp->id)
                ->firstOrFail()
            : null;
        $build = $create->handle(
            $team,
            $mobileApp,
            $request->user(),
            $data['request_uuid'],
            ProfileType::from($data['profile_type']),
            $identifier,
            $data['context'] ?? [],
            $data['parameters'] ?? [],
        );

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
    ): BuildRecordResource|JsonResponse {
        $this->assertBuild($team, $mobileApp, $buildRecord);
        abort_unless($buildRecord->reporting_mode === BuildReportingMode::Manual, 409, 'This build is managed automatically.');
        $data = $request->validate([
            'status' => ['required', Rule::in([
                BuildStatus::Running->value,
                BuildStatus::Completed->value,
                BuildStatus::Failed->value,
                BuildStatus::Cancelled->value,
            ])],
        ]);
        $status = BuildStatus::from($data['status']);

        try {
            $build = $status === BuildStatus::Running
                ? $lifecycle->start($buildRecord)
                : $lifecycle->finish($buildRecord, $status);
        } catch (InvalidBuildTransition $exception) {
            return response()->json(['message' => $exception->getMessage()], 409);
        }

        if ($build->status !== $status) {
            return response()->json([
                'message' => "Build cannot transition from {$build->status->value} to {$status->value}.",
            ], 409);
        }

        return new BuildRecordResource($build);
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

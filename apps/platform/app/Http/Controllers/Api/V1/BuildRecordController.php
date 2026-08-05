<?php

namespace App\Http\Controllers\Api\V1;

use App\Enums\BuildStatus;
use App\Enums\Platform;
use App\Enums\ProfileType;
use App\Http\Controllers\Controller;
use App\Http\Resources\Api\V1\BuildRecordResource;
use App\Models\BuildRecord;
use App\Models\MobileApp;
use App\Models\Team;
use App\Support\Builds\BuildContextResolver;
use App\Support\Builds\BuildLifecycle;
use App\Support\Builds\BuildSnapshotFactory;
use Illuminate\Database\QueryException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

class BuildRecordController extends Controller
{
    public function index(Team $team, MobileApp $mobileApp): AnonymousResourceCollection
    {
        return BuildRecordResource::collection(
            $mobileApp->buildRecords()->withMax('logs', 'sequence')->latest()->get(),
        );
    }

    public function store(
        Request $request,
        Team $team,
        MobileApp $mobileApp,
        BuildContextResolver $resolver,
        BuildSnapshotFactory $snapshots,
    ): JsonResponse {
        $this->authorize('manageCredentials', $team);

        $data = $request->validate([
            'platform' => ['required', Rule::enum(Platform::class)],
            'profile_type' => ['required', Rule::enum(ProfileType::class)],
            'identifier' => ['sometimes', 'string', 'max:255'],
            'external_id' => ['nullable', 'string', 'max:255', 'regex:/\A[A-Za-z0-9][A-Za-z0-9._:-]*\z/'],
            'commit_sha' => ['nullable', 'string', 'regex:/^[a-fA-F0-9]{7,64}$/'],
            'ci_provider' => ['nullable', 'string', 'max:255', 'required_with:ci_run_id'],
            'ci_run_id' => ['nullable', 'string', 'max:255', 'required_with:ci_provider'],
            'ci_run_url' => ['nullable', 'url', 'max:2048'],
        ]);

        $externalId = $data['external_id'] ?? Str::uuid()->toString();
        $existing = $team->buildRecords()
            ->where('external_id', $externalId)
            ->first();

        if ($existing) {
            abort_unless($existing->mobile_app_id === $mobileApp->id, 409);

            return (new BuildRecordResource($existing))
                ->response()
                ->setStatusCode(200);
        }

        if (filled($data['ci_provider'] ?? null) && filled($data['ci_run_id'] ?? null)) {
            $existing = $team->buildRecords()
                ->where('ci_provider', $data['ci_provider'])
                ->where('ci_run_id', $data['ci_run_id'])
                ->first();

            if ($existing) {
                abort_unless($existing->mobile_app_id === $mobileApp->id, 409);

                return (new BuildRecordResource($existing))
                    ->response()
                    ->setStatusCode(200);
            }
        }

        $context = $resolver->resolve(
            $mobileApp,
            Platform::from($data['platform']),
            ProfileType::from($data['profile_type']),
            $data['identifier'] ?? null,
        );
        $snapshot = $snapshots->make($mobileApp, $context);
        try {
            $record = $team->buildRecords()->create([
                'mobile_app_id' => $mobileApp->id,
                'app_identifier_id' => $context['target']->id,
                'external_id' => $externalId,
                'profile_type' => $data['profile_type'],
                'status' => BuildStatus::Pending,
                'commit_sha' => $data['commit_sha'] ?? null,
                'ci_provider' => $data['ci_provider'] ?? null,
                'ci_run_id' => $data['ci_run_id'] ?? null,
                'ci_run_url' => $data['ci_run_url'] ?? null,
                'context_schema_version' => BuildSnapshotFactory::SCHEMA_VERSION,
                'context_snapshot' => $snapshot,
                'started_at' => null,
            ]);
        } catch (QueryException $exception) {
            $record = $team->buildRecords()->where('external_id', $externalId)->first();

            if (! $record && filled($data['ci_provider'] ?? null) && filled($data['ci_run_id'] ?? null)) {
                $record = $team->buildRecords()
                    ->where('ci_provider', $data['ci_provider'])
                    ->where('ci_run_id', $data['ci_run_id'])
                    ->first();
            }

            if (! $record) {
                throw $exception;
            }

            abort_unless($record->mobile_app_id === $mobileApp->id, 409);

            return (new BuildRecordResource($record))
                ->response()
                ->setStatusCode(200);
        }

        return (new BuildRecordResource($record))
            ->response()
            ->setStatusCode(201);
    }

    public function show(Team $team, MobileApp $mobileApp, BuildRecord $buildRecord): BuildRecordResource
    {
        return new BuildRecordResource($buildRecord);
    }

    public function start(
        Request $request,
        Team $team,
        MobileApp $mobileApp,
        BuildRecord $buildRecord,
        BuildLifecycle $lifecycle,
    ): BuildRecordResource {
        $this->authorize('manageCredentials', $team);

        $data = $request->validate([
            'ci_provider' => ['required', 'string', 'max:255'],
            'ci_run_id' => ['required', 'string', 'max:255'],
            'ci_run_url' => ['nullable', 'url', 'max:2048'],
        ]);

        return new BuildRecordResource($lifecycle->start($buildRecord, $data));
    }

    public function update(
        Request $request,
        Team $team,
        MobileApp $mobileApp,
        BuildRecord $buildRecord,
        BuildLifecycle $lifecycle,
    ): BuildRecordResource {
        $this->authorize('manageCredentials', $team);

        $data = $request->validate([
            'status' => ['required', Rule::in([
                BuildStatus::Completed->value,
                BuildStatus::Failed->value,
                BuildStatus::Cancelled->value,
            ])],
            'artifact_url' => ['sometimes', 'nullable', 'url', 'max:2048'],
            'context_snapshot' => ['prohibited'],
            'context_schema_version' => ['prohibited'],
        ]);
        $status = BuildStatus::from($data['status']);

        return new BuildRecordResource($lifecycle->finish(
            $buildRecord,
            $status,
            $data['artifact_url'] ?? null,
        ));
    }
}

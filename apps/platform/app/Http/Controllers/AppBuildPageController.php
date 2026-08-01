<?php

namespace App\Http\Controllers;

use App\Enums\TeamPermission;
use App\Models\BuildRecord;
use App\Models\MobileApp;
use App\Models\Team;
use App\Support\Apps\AppContext;
use App\Support\Apps\BuildPresenter;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

class AppBuildPageController extends Controller
{
    public function index(Request $request, Team $team, MobileApp $mobileApp): Response
    {
        $this->authorizeTeam($request, $team);
        [$canRun, $canManage] = $this->permissions($request, $team);
        $filters = $request->validate([
            'tags' => ['nullable', 'array', 'max:20'],
            'tags.*' => ['string', 'max:100'],
            'statuses' => ['nullable', 'array', 'max:6'],
            'statuses.*' => [Rule::in(['pending', 'running', 'cancel_requested', 'completed', 'failed', 'cancelled'])],
            'started_from' => ['nullable', 'date_format:Y-m-d'],
            'started_to' => ['nullable', 'date_format:Y-m-d'],
            'duration_min' => ['nullable', 'integer', 'min:0', 'max:100000'],
            'duration_max' => ['nullable', 'integer', 'min:0', 'max:100000'],
            'artifacts' => ['nullable', Rule::in(['with', 'without'])],
            'reporting_mode' => ['nullable', Rule::in(['manual', 'automatic'])],
            'pipelines' => ['nullable', 'array', 'max:50'],
            'pipelines.*' => ['string', 'max:100'],
        ]);

        $filters['tags'] = $this->cleanArrayFilter($filters['tags'] ?? []);
        $filters['statuses'] = $this->cleanArrayFilter($filters['statuses'] ?? []);
        $filters['pipelines'] = $this->cleanArrayFilter($filters['pipelines'] ?? []);

        $query = $mobileApp->buildRecords()
            ->with(['ciCdRun.pipeline', 'artifacts.media'])
            ->latest();

        if ($filters['tags'] !== []) {
            $query->whereAnyTag($filters['tags']);
        }
        if ($filters['statuses'] !== []) {
            $query->whereIn('status', $filters['statuses']);
        }
        if ($from = $filters['started_from'] ?? null) {
            $query->whereDate('started_at', '>=', $from);
        }
        if ($to = $filters['started_to'] ?? null) {
            $query->whereDate('started_at', '<=', $to);
        }
        if ($mode = $filters['reporting_mode'] ?? null) {
            $query->where('reporting_mode', $mode);
        }
        if (($artifacts = $filters['artifacts'] ?? null) === 'with') {
            $query->has('artifacts');
        } elseif ($artifacts === 'without') {
            $query->doesntHave('artifacts');
        }
        if ($filters['pipelines'] !== []) {
            $query->whereHas('ciCdRun', fn ($run) => $run->whereIn('pipeline_id', $filters['pipelines']));
        }
        if (($minimum = $filters['duration_min'] ?? null) !== null) {
            $this->applyDurationFilter($query, '>=', $minimum);
        }
        if (($maximum = $filters['duration_max'] ?? null) !== null) {
            $this->applyDurationFilter($query, '<=', $maximum);
        }

        $builds = $query->limit(30)->get()
            ->map(fn ($build) => BuildPresenter::present($team, $mobileApp, $build, $canRun, $canManage));

        return Inertia::render('Apps/Builds/Index', [
            ...AppContext::for($request, $team, $mobileApp),
            'active' => 'builds',
            'builds' => $builds,
            'filters' => $filters,
            'filterTags' => $mobileApp->buildRecords()->pluck('tags')
                ->flatten(1)
                ->filter(fn ($tag) => filled(is_array($tag) ? ($tag[0] ?? null) : $tag))
                ->map(fn ($tag) => is_array($tag) ? [$tag[0], $tag[1] ?? null] : [$tag, null])
                ->unique(fn ($tag) => mb_strtolower($tag[0]))
                ->sortBy(fn ($tag) => mb_strtolower($tag[0]))
                ->values(),
            'filterPipelines' => $mobileApp->ciCdPipelines()
                ->orderBy('name')->get(['id', 'name'])
                ->map(fn ($pipeline) => ['id' => $pipeline->id, 'name' => $pipeline->name])->values(),
        ]);
    }

    public function show(Request $request, Team $team, MobileApp $mobileApp, BuildRecord $buildRecord): Response
    {
        $this->authorizeTeam($request, $team);
        [$canRun, $canManage] = $this->permissions($request, $team);
        $buildRecord->load(['ciCdRun.pipeline', 'artifacts.media']);

        return Inertia::render('Apps/Builds/Show', [
            ...AppContext::for($request, $team, $mobileApp),
            'active' => 'builds',
            'build' => BuildPresenter::present($team, $mobileApp, $buildRecord, $canRun, $canManage),
        ]);
    }

    private function permissions(Request $request, Team $team): array
    {
        return [
            $team->allows($request->user(), TeamPermission::RunPipelines),
            $team->allows($request->user(), TeamPermission::ManageApplications),
        ];
    }

    private function authorizeTeam(Request $request, Team $team): void
    {
        abort_unless($request->user()->teams()->whereKey($team->id)->exists(), 403);
    }

    private function applyDurationFilter($query, string $operator, int $minutes): void
    {
        $driver = $query->getConnection()->getDriverName();
        $expression = match ($driver) {
            'pgsql' => 'EXTRACT(EPOCH FROM (finished_at - started_at)) / 60',
            'mysql', 'mariadb' => 'TIMESTAMPDIFF(MINUTE, started_at, finished_at)',
            default => '(julianday(finished_at) - julianday(started_at)) * 1440',
        };

        $query->whereNotNull('started_at')
            ->whereNotNull('finished_at')
            ->whereRaw("{$expression} {$operator} ?", [$minutes]);
    }

    private function cleanArrayFilter(array $values): array
    {
        return collect($values)
            ->map(fn ($value) => trim((string) $value))
            ->filter()
            ->unique()
            ->values()
            ->all();
    }
}

<?php

namespace App\Http\Controllers;

use App\Enums\BuildReportingMode;
use App\Enums\BuildStatus;
use App\Enums\Platform;
use App\Models\BuildRecord;
use App\Models\Team;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

class TeamBuildPageController extends Controller
{
    public function __invoke(Request $request, Team $team): Response
    {
        $filters = $request->validate([
            'search' => ['nullable', 'string', 'max:100'],
            'apps' => ['nullable', 'array', 'max:50'],
            'apps.*' => ['string', 'max:100'],
            'identifiers' => ['nullable', 'array', 'max:100'],
            'identifiers.*' => ['string', 'max:100'],
            'platforms' => ['nullable', 'array', 'max:3'],
            'platforms.*' => [Rule::enum(Platform::class)],
            'sources' => ['nullable', 'array', 'max:3'],
            'sources.*' => [Rule::in(['manual', 'pipeline', 'expo'])],
            'statuses' => ['nullable', 'array', 'max:6'],
            'statuses.*' => [Rule::enum(BuildStatus::class)],
            'started_from' => ['nullable', 'date_format:Y-m-d'],
            'started_to' => ['nullable', 'date_format:Y-m-d'],
        ]);

        $filters = [
            'search' => trim((string) ($filters['search'] ?? '')),
            'apps' => $this->cleanList($filters['apps'] ?? []),
            'identifiers' => $this->cleanList($filters['identifiers'] ?? []),
            'platforms' => $this->cleanList($filters['platforms'] ?? []),
            'sources' => $this->cleanList($filters['sources'] ?? []),
            'statuses' => $this->cleanList($filters['statuses'] ?? []),
            'started_from' => $filters['started_from'] ?? '',
            'started_to' => $filters['started_to'] ?? '',
        ];

        $query = BuildRecord::query()
            ->where('team_id', $team->id)
            ->with(['mobileApp', 'appIdentifier', 'ciCdRun.pipeline'])
            ->withCount('artifacts');

        $this->applyFilters($query, $filters);

        $statusCounts = (clone $query)
            ->reorder()
            ->select('status')
            ->selectRaw('COUNT(*) as aggregate')
            ->groupBy('status')
            ->pluck('aggregate', 'status');

        $counts = collect(BuildStatus::cases())->mapWithKeys(fn (BuildStatus $status) => [
            $status->value => (int) ($statusCounts[$status->value] ?? 0),
        ]);
        $decided = $counts['completed'] + $counts['failed'];

        $recent = (clone $query)
            ->latest('created_at')
            ->limit(18)
            ->get()
            ->map(fn (BuildRecord $build) => $this->presentRunwayBuild($team, $build))
            ->values();

        $builds = $query
            ->latest('created_at')
            ->paginate(25)
            ->withQueryString()
            ->through(fn (BuildRecord $build) => $this->presentBuild($team, $build));

        return Inertia::render('Builds/Index', [
            'active' => 'builds',
            'builds' => $builds,
            'filters' => $filters,
            'filterOptions' => [
                'apps' => $team->mobileApps()
                    ->orderBy('name')
                    ->get(['id', 'name'])
                    ->map(fn ($app) => ['value' => $app->id, 'label' => $app->name])
                    ->values(),
                'identifiers' => $team->mobileApps()
                    ->with(['appIdentifiers' => fn ($query) => $query->orderBy('identifier')])
                    ->orderBy('name')
                    ->get(['id', 'name'])
                    ->flatMap(fn ($app) => $app->appIdentifiers->map(fn ($identifier) => [
                        'value' => $identifier->id,
                        'label' => $identifier->identifier,
                        'description' => $app->name.' · '.$this->platformLabel($identifier->platform->value),
                    ]))
                    ->values(),
                'platforms' => collect(Platform::cases())->map(fn (Platform $platform) => [
                    'value' => $platform->value,
                    'label' => $this->platformLabel($platform->value),
                ])->values(),
                'sources' => [
                    ['value' => 'manual', 'label' => 'Manual'],
                    ['value' => 'pipeline', 'label' => 'Pipeline'],
                    ['value' => 'expo', 'label' => 'Expo EAS'],
                ],
                'statuses' => collect(BuildStatus::cases())->map(fn (BuildStatus $status) => [
                    'value' => $status->value,
                    'label' => str($status->value)->replace('_', ' ')->title()->toString(),
                ])->values(),
            ],
            'monitor' => [
                'total' => $counts->sum(),
                'active' => $counts['pending'] + $counts['running'] + $counts['cancel_requested'],
                'failed' => $counts['failed'],
                'completed' => $counts['completed'],
                'success_rate' => $decided > 0 ? (int) round(($counts['completed'] / $decided) * 100) : null,
                'counts' => $counts,
                'recent' => $recent,
            ],
        ]);
    }

    private function applyFilters(Builder $query, array $filters): void
    {
        if ($filters['apps'] !== []) {
            $query->whereIn('mobile_app_id', $filters['apps']);
        }
        if ($filters['identifiers'] !== []) {
            $query->whereIn('app_identifier_id', $filters['identifiers']);
        }
        if ($filters['platforms'] !== []) {
            $query->whereHas('appIdentifier', fn (Builder $identifiers) => $identifiers
                ->whereIn('platform', $filters['platforms']));
        }
        if ($filters['statuses'] !== []) {
            $query->whereIn('status', $filters['statuses']);
        }
        if ($filters['sources'] !== []) {
            $modes = collect($filters['sources'])->map(fn (string $source) => match ($source) {
                'pipeline' => BuildReportingMode::Automatic->value,
                'expo' => BuildReportingMode::Eas->value,
                default => BuildReportingMode::Manual->value,
            })->all();

            $query->whereIn('reporting_mode', $modes);
        }
        if ($filters['started_from'] !== '') {
            $query->whereDate('started_at', '>=', $filters['started_from']);
        }
        if ($filters['started_to'] !== '') {
            $query->whereDate('started_at', '<=', $filters['started_to']);
        }
        if ($filters['search'] !== '') {
            $pattern = '%'.$filters['search'].'%';
            $uuidCast = match ($query->getConnection()->getDriverName()) {
                'mysql', 'mariadb' => fn (string $column) => "CAST({$column} AS CHAR)",
                default => fn (string $column) => "CAST({$column} AS TEXT)",
            };

            $query->where(function (Builder $search) use ($pattern, $uuidCast) {
                $search->whereRaw('('.$uuidCast('build_records.id').' LIKE ? OR '.$uuidCast('build_records.request_uuid').' LIKE ?)', [$pattern, $pattern])
                    ->orWhereLike('expo_build_id', $pattern)
                    ->orWhereHas('mobileApp', fn (Builder $apps) => $apps
                        ->whereLike('name', $pattern)
                        ->orWhereLike('slug', $pattern))
                    ->orWhereHas('appIdentifier', fn (Builder $identifiers) => $identifiers
                        ->whereLike('identifier', $pattern)
                        ->orWhereLike('name', $pattern))
                    ->orWhereHas('ciCdRun.pipeline', fn (Builder $pipelines) => $pipelines
                        ->whereLike('name', $pattern));
            });
        }
    }

    private function presentBuild(Team $team, BuildRecord $build): array
    {
        $app = $build->mobileApp;
        $identifier = $build->appIdentifier;
        $source = $this->source($build);

        return [
            'id' => $build->id,
            'request_uuid' => $build->request_uuid,
            'status' => $build->status->value,
            'profile_type' => $build->profile_type->value,
            'source' => $source,
            'source_label' => match ($source) {
                'pipeline' => $build->ciCdRun?->pipeline?->name ?? 'Pipeline',
                'expo' => 'Expo EAS',
                default => 'Manual',
            },
            'app' => [
                'id' => $app->id,
                'name' => $app->name,
                'slug' => $app->slug,
                'url' => route('accounts.apps.show', [$team, $app]),
            ],
            'identifier' => $identifier ? [
                'id' => $identifier->id,
                'name' => $identifier->name,
                'identifier' => $identifier->identifier,
                'platform' => $identifier->platform->value,
                'platform_label' => $this->platformLabel($identifier->platform->value),
                'url' => route('accounts.apps.identifiers.show', [$team, $app, $identifier]),
            ] : null,
            'artifacts_count' => $build->artifacts_count,
            'started_at' => $build->started_at,
            'finished_at' => $build->finished_at,
            'created_at' => $build->created_at,
            'show_url' => route('accounts.apps.builds.show', [$team, $app, $build]),
        ];
    }

    private function presentRunwayBuild(Team $team, BuildRecord $build): array
    {
        return [
            'id' => $build->id,
            'status' => $build->status->value,
            'app_name' => $build->mobileApp->name,
            'platform' => $build->appIdentifier?->platform?->value,
            'source' => $this->source($build),
            'started_at' => $build->started_at ?? $build->created_at,
            'show_url' => route('accounts.apps.builds.show', [$team, $build->mobileApp, $build]),
        ];
    }

    private function source(BuildRecord $build): string
    {
        return match ($build->reporting_mode) {
            BuildReportingMode::Automatic => 'pipeline',
            BuildReportingMode::Eas => 'expo',
            default => 'manual',
        };
    }

    private function platformLabel(string $platform): string
    {
        return match ($platform) {
            'ios' => 'iOS',
            'android' => 'Android',
            default => 'Web',
        };
    }

    /** @return array<int, string> */
    private function cleanList(array $values): array
    {
        return collect($values)
            ->map(fn ($value) => trim((string) $value))
            ->filter()
            ->unique()
            ->values()
            ->all();
    }
}

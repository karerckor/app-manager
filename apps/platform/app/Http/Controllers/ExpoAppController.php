<?php

namespace App\Http\Controllers;

use App\Enums\IntegrationStatus;
use App\Integrations\Expo\ExpoClient;
use App\Integrations\Expo\ExpoGraphqlException;
use App\Models\BuildRecord;
use App\Models\MobileApp;
use App\Models\Team;
use App\Support\Apps\AppContext;
use App\Support\Apps\ExpoAppContext;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class ExpoAppController extends Controller
{
    private const ACTIVITY_PAGE_SIZE = 10;

    public function __invoke(
        Request $request,
        Team $team,
        MobileApp $mobileApp,
        ExpoClient $expo,
    ): Response {
        abort_unless(
            config('integrations.enabled') && config('integrations.expo.enabled'),
            404,
        );
        abort_unless($request->user()->teams()->whereKey($team->id)->exists(), 403);
        abort_unless(ExpoAppContext::available($team, $mobileApp), 404);

        $project = $mobileApp->expoProject()
            ->whereHas('integration', fn ($query) => $query->connectedExpo())
            ->with('integration')
            ->first();
        $remote = null;
        $error = null;
        $activityTab = in_array($request->query('tab'), ['builds', 'submissions'], true)
            ? $request->query('tab')
            : 'builds';
        $buildPage = max(1, $request->integer('build_page', 1));
        $submissionPage = max(1, $request->integer('submission_page', 1));

        if ($project
            && $project->exists_on_expo
            && $project->integration->status === IntegrationStatus::Active) {
            try {
                $remote = $expo->project(
                    $project->integration,
                    $project->external_id,
                    buildLimit: self::ACTIVITY_PAGE_SIZE + 1,
                    buildOffset: ($buildPage - 1) * self::ACTIVITY_PAGE_SIZE,
                    submissionLimit: self::ACTIVITY_PAGE_SIZE + 1,
                    submissionOffset: ($submissionPage - 1) * self::ACTIVITY_PAGE_SIZE,
                );
            } catch (ExpoGraphqlException $exception) {
                $error = $exception->getMessage();
            }
        }

        $integrations = $team->integrations()
            ->activeExpo()
            ->with(['expoProjects' => fn ($query) => $query
                ->whereNull('mobile_app_id')
                ->where('exists_on_expo', true)
                ->orderBy('name')])
            ->orderBy('external_account_name')
            ->get();

        return Inertia::render('Apps/Expo/Index', [
            ...AppContext::for($request, $team, $mobileApp),
            'active' => 'expo',
            'expo' => [
                ...ExpoAppContext::summary($request, $team, $mobileApp),
                'error' => $error,
                'setup_url' => route('accounts.apps.expo.setup', [$team, $mobileApp]),
                'sync_url' => $project
                    ? route('accounts.apps.expo.sync', [$team, $mobileApp])
                    : null,
                'unlink_url' => $project
                    ? route('accounts.apps.expo.unlink', [$team, $mobileApp])
                    : null,
                'import_builds_url' => $project
                    ? route('accounts.apps.expo.builds.import', [$team, $mobileApp])
                    : null,
                'accounts' => $integrations->map(fn ($integration) => [
                    'id' => $integration->id,
                    'name' => $integration->external_account_name,
                    'projects' => $integration->expoProjects->map(fn ($candidate) => [
                        'id' => $candidate->id,
                        'name' => $candidate->name,
                        'slug' => $candidate->slug,
                        'full_name' => $candidate->full_name,
                    ])->values(),
                ])->values(),
                'remote' => $remote ? $this->presentRemote(
                    $team,
                    $mobileApp,
                    $remote,
                    $activityTab,
                    $buildPage,
                    $submissionPage,
                ) : null,
            ],
        ]);
    }

    /** @return array<string, mixed> */
    private function presentRemote(
        Team $team,
        MobileApp $app,
        array $remote,
        string $activityTab,
        int $buildPage,
        int $submissionPage,
    ): array {
        $buildAction = fn (string $id, string $action) => route(
            "accounts.apps.expo.builds.{$action}",
            [$team, $app, 'buildId' => $id],
        );
        $submissionAction = fn (string $id, string $action) => route(
            "accounts.apps.expo.submissions.{$action}",
            [$team, $app, 'submissionId' => $id],
        );
        $builds = array_values((array) ($remote['builds'] ?? []));
        $submissions = array_values((array) ($remote['submissions'] ?? []));
        $importedBuildIds = BuildRecord::query()
            ->where('team_id', $team->id)
            ->where('mobile_app_id', $app->id)
            ->whereIn('expo_build_id', array_map(
                fn ($build) => (string) ($build['id'] ?? ''),
                array_slice($builds, 0, self::ACTIVITY_PAGE_SIZE),
            ))
            ->pluck('expo_build_id')
            ->all();
        $buildHasNext = count($builds) > self::ACTIVITY_PAGE_SIZE;
        $submissionHasNext = count($submissions) > self::ACTIVITY_PAGE_SIZE;
        $activityUrl = fn (string $tab, int $nextBuildPage, int $nextSubmissionPage) => route(
            'accounts.apps.expo.index',
            [
                $team,
                $app,
                'tab' => $tab,
                'build_page' => $nextBuildPage,
                'submission_page' => $nextSubmissionPage,
            ],
        );

        return [
            'build_profiles' => array_values((array) ($remote['buildProfiles'] ?? [])),
            'automation' => $this->automation($team, $app, $remote),
            'credentials' => [
                'android' => array_values((array) ($remote['androidAppCredentials'] ?? [])),
                'ios' => array_values((array) ($remote['iosAppCredentials'] ?? [])),
            ],
            'activity' => [
                'active' => $activityTab,
                'builds_url' => $activityUrl('builds', $buildPage, $submissionPage),
                'submissions_url' => $activityUrl('submissions', $buildPage, $submissionPage),
            ],
            'pagination' => [
                'builds' => [
                    'page' => $buildPage,
                    'per_page' => self::ACTIVITY_PAGE_SIZE,
                    'previous_url' => $buildPage > 1
                        ? $activityUrl('builds', $buildPage - 1, $submissionPage)
                        : null,
                    'next_url' => $buildHasNext
                        ? $activityUrl('builds', $buildPage + 1, $submissionPage)
                        : null,
                ],
                'submissions' => [
                    'page' => $submissionPage,
                    'per_page' => self::ACTIVITY_PAGE_SIZE,
                    'previous_url' => $submissionPage > 1
                        ? $activityUrl('submissions', $buildPage, $submissionPage - 1)
                        : null,
                    'next_url' => $submissionHasNext
                        ? $activityUrl('submissions', $buildPage, $submissionPage + 1)
                        : null,
                ],
            ],
            'builds' => collect(array_slice($builds, 0, self::ACTIVITY_PAGE_SIZE))->map(fn ($build) => [
                ...$build,
                'already_imported' => in_array((string) $build['id'], $importedBuildIds, true),
                'cancel_url' => $buildAction((string) $build['id'], 'cancel'),
                'retry_url' => $buildAction((string) $build['id'], 'retry'),
                'submit_url' => route('accounts.apps.expo.submissions.store', [$team, $app]),
                'expo_url' => rtrim((string) config('integrations.expo.web_url'), '/')
                    .'/accounts/'.$remote['ownerAccount']['name']
                    .'/projects/'.$remote['slug']
                    .'/builds/'.$build['id'],
            ])->values(),
            'submissions' => collect(array_slice($submissions, 0, self::ACTIVITY_PAGE_SIZE))->map(fn ($submission) => [
                ...$submission,
                'cancel_url' => $submissionAction((string) $submission['id'], 'cancel'),
                'retry_url' => $submissionAction((string) $submission['id'], 'retry'),
                'expo_url' => rtrim((string) config('integrations.expo.web_url'), '/')
                    .'/accounts/'.$remote['ownerAccount']['name']
                    .'/projects/'.$remote['slug']
                    .'/submissions/'.$submission['id'],
            ])->values(),
        ];
    }

    /** @return array<string, mixed> */
    private function automation(Team $team, MobileApp $app, array $remote): array
    {
        $github = is_array($remote['githubRepository'] ?? null)
            ? $remote['githubRepository']
            : null;
        $workflows = collect((array) ($remote['workflows'] ?? []))
            ->filter(fn ($workflow) => is_array($workflow) && filled($workflow['fileName'] ?? null))
            ->map(fn (array $workflow) => [
                'id' => (string) ($workflow['id'] ?? $workflow['fileName']),
                'name' => (string) ($workflow['name'] ?? $workflow['fileName']),
                'file_name' => (string) $workflow['fileName'],
            ])
            ->values();
        $configured = $app->ciCdPipelines()
            ->whereHas('integration', fn ($query) => $query
                ->where('provider', 'expo'))
            ->where('enabled', true)
            ->count();
        $projectUrl = rtrim((string) config('integrations.expo.web_url'), '/')
            .'/accounts/'.rawurlencode((string) data_get($remote, 'ownerAccount.name', ''))
            .'/projects/'.rawurlencode((string) ($remote['slug'] ?? ''));

        if ($github === null) {
            $state = 'github_not_connected';
            $message = 'The app is linked to Expo, but EAS has no GitHub repository to read source and workflow files from.';
            $actionUrl = $projectUrl;
            $actionExternal = true;
        } elseif ($workflows->isEmpty()) {
            $state = 'workflows_not_configured';
            $message = 'GitHub is connected in EAS, but no workflow files are available in .eas/workflows.';
            $actionUrl = $projectUrl.'/workflows';
            $actionExternal = true;
        } elseif ($configured === 0) {
            $state = 'pipeline_not_configured';
            $message = 'EAS Workflows are available. Configure one as an App Manager build pipeline.';
            $actionUrl = route('accounts.integrations.index', $team);
            $actionExternal = false;
        } else {
            $state = 'ready';
            $message = $configured.' EAS Workflow '.str('pipeline')->plural($configured).' ready to run from App Manager.';
            $actionUrl = route('accounts.apps.builds.index', [$team, $app]);
            $actionExternal = false;
        }

        return [
            'state' => $state,
            'message' => $message,
            'github_repository' => $github ? [
                'name' => collect([
                    data_get($github, 'metadata.githubRepoOwnerName'),
                    data_get($github, 'metadata.githubRepoName'),
                ])->filter()->implode('/'),
                'url' => data_get($github, 'metadata.githubRepoUrl')
                    ?? data_get($github, 'githubRepositoryUrl'),
                'default_branch' => data_get($github, 'metadata.defaultBranch'),
            ] : null,
            'workflows' => $workflows,
            'configured_pipelines' => $configured,
            'action_url' => $actionUrl,
            'action_external' => $actionExternal,
        ];
    }
}

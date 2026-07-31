<?php

namespace App\Http\Controllers;

use App\Enums\IntegrationStatus;
use App\Enums\TeamPermission;
use App\Integrations\Expo\ExpoClient;
use App\Integrations\Expo\ExpoGraphqlException;
use App\Models\ExpoProject;
use App\Models\MobileApp;
use App\Models\Team;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class ExpoSubmissionController extends Controller
{
    public function store(
        Request $request,
        Team $team,
        MobileApp $mobileApp,
        ExpoClient $expo,
    ): RedirectResponse {
        [$project, $remote] = $this->context($request, $team, $mobileApp, $expo);
        $data = $request->validate([
            'build_id' => ['required', 'string', 'max:255'],
            'platform' => ['required', Rule::in(['ANDROID', 'IOS'])],
            'application_identifier' => ['required', 'string', 'max:255'],
            'track' => [
                Rule::requiredIf($request->input('platform') === 'ANDROID'),
                Rule::in(['ALPHA', 'BETA', 'INTERNAL', 'PRODUCTION']),
            ],
            'release_status' => [
                Rule::requiredIf($request->input('platform') === 'ANDROID'),
                Rule::in(['COMPLETED', 'DRAFT', 'HALTED', 'IN_PROGRESS']),
            ],
            'google_service_account_key_id' => ['nullable', 'string', 'max:255'],
            'asc_api_key_id' => ['nullable', 'string', 'max:255'],
        ]);
        $build = collect((array) ($remote['builds'] ?? []))
            ->firstWhere('id', $data['build_id']);
        abort_unless(
            is_array($build) && ($build['platform'] ?? null) === $data['platform'],
            404,
        );

        $config = $data['platform'] === 'IOS'
            ? array_filter([
                'ascAppIdentifier' => $data['application_identifier'],
                'ascApiKeyId' => $data['asc_api_key_id'] ?? null,
            ], fn ($value) => $value !== null && $value !== '')
            : array_filter([
                'applicationIdentifier' => $data['application_identifier'],
                'track' => $data['track'],
                'releaseStatus' => $data['release_status'],
                'googleServiceAccountKeyId' => $data['google_service_account_key_id'] ?? null,
            ], fn ($value) => $value !== null && $value !== '');

        try {
            $expo->createSubmission(
                $project->integration,
                $project->external_id,
                $data['build_id'],
                $data['platform'],
                $config,
            );
        } catch (ExpoGraphqlException $exception) {
            throw ValidationException::withMessages(['expo' => $exception->getMessage()]);
        }

        return back()->with('status', 'Expo submission started.');
    }

    public function cancel(
        Request $request,
        Team $team,
        MobileApp $mobileApp,
        string $submissionId,
        ExpoClient $expo,
    ): RedirectResponse {
        [$project, $remote] = $this->context($request, $team, $mobileApp, $expo);
        abort_unless(
            collect((array) ($remote['submissions'] ?? []))->contains('id', $submissionId),
            404,
        );

        try {
            $expo->cancelSubmission($project->integration, $submissionId);
        } catch (ExpoGraphqlException $exception) {
            throw ValidationException::withMessages(['expo' => $exception->getMessage()]);
        }

        return back()->with('status', 'Expo submission cancellation requested.');
    }

    public function retry(
        Request $request,
        Team $team,
        MobileApp $mobileApp,
        string $submissionId,
        ExpoClient $expo,
    ): RedirectResponse {
        [$project, $remote] = $this->context($request, $team, $mobileApp, $expo);
        abort_unless(
            collect((array) ($remote['submissions'] ?? []))->contains('id', $submissionId),
            404,
        );

        try {
            $expo->retrySubmission($project->integration, $submissionId);
        } catch (ExpoGraphqlException $exception) {
            throw ValidationException::withMessages(['expo' => $exception->getMessage()]);
        }

        return back()->with('status', 'Expo submission retry started.');
    }

    /** @return array{0: ExpoProject, 1: array<string, mixed>} */
    private function context(
        Request $request,
        Team $team,
        MobileApp $mobileApp,
        ExpoClient $expo,
    ): array {
        abort_unless(
            config('integrations.enabled') && config('integrations.expo.enabled'),
            404,
        );
        abort_unless($team->allows($request->user(), TeamPermission::RunPipelines), 403);
        $project = $mobileApp->expoProject()->with('integration')->firstOrFail();
        abort_unless(
            $project->team_id === $team->id
            && $project->integration->status === IntegrationStatus::Active,
            404,
        );

        try {
            $remote = $expo->project($project->integration, $project->external_id);
        } catch (ExpoGraphqlException $exception) {
            throw ValidationException::withMessages(['expo' => $exception->getMessage()]);
        }

        return [$project, $remote];
    }
}

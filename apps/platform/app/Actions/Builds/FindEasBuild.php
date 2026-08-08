<?php

namespace App\Actions\Builds;

use App\Integrations\Expo\ExpoClient;
use App\Integrations\Expo\ExpoGraphqlException;
use App\Models\ExpoProject;
use App\Models\MobileApp;
use App\Models\Team;
use Illuminate\Validation\ValidationException;

class FindEasBuild
{
    public function __construct(private readonly ExpoClient $expoClient) {}

    /** @return array{project: ExpoProject, build: array<string, mixed>} */
    public function handle(Team $team, MobileApp $mobileApp, string $expoBuildId): array
    {
        $expoProject = ExpoProject::query()
            ->where('mobile_app_id', $mobileApp->id)
            ->where('team_id', $team->id)
            ->with('integration')
            ->first();

        if (! $expoProject) {
            throw ValidationException::withMessages([
                'expo' => 'This application is not connected to EAS. Please link an Expo project first.',
            ]);
        }

        if (! $expoProject->exists_on_expo) {
            throw ValidationException::withMessages([
                'expo' => 'This Expo project appears to be detached. The project no longer exists on expo.dev.',
            ]);
        }

        $integration = $expoProject->integration;

        if (! $integration || ! $integration->status->canDispatch()) {
            throw ValidationException::withMessages([
                'expo' => 'The Expo integration is not active. Please reconnect your Expo account.',
            ]);
        }

        try {
            $projectData = $this->expoClient->project($integration, $expoProject->external_id, buildLimit: 50);
        } catch (ExpoGraphqlException $exception) {
            throw ValidationException::withMessages([
                'expo_build_id' => 'Unable to fetch builds from Expo: '.$exception->getMessage(),
            ]);
        }

        $easBuild = collect((array) ($projectData['builds'] ?? []))
            ->firstWhere('id', $expoBuildId);

        if (! is_array($easBuild) || empty($easBuild['id'])) {
            throw ValidationException::withMessages([
                'expo_build_id' => 'The specified EAS build ID was not found in recent builds. It may be too old or belong to a different project.',
            ]);
        }

        return ['project' => $expoProject, 'build' => $easBuild];
    }
}

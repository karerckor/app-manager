<?php

namespace App\Http\Controllers;

use App\Enums\IntegrationStatus;
use App\Enums\TeamPermission;
use App\Integrations\Expo\ExpoCredentialExporter;
use App\Integrations\Expo\ExpoGraphqlException;
use App\Models\AppIdentifier;
use App\Models\Credential;
use App\Models\MobileApp;
use App\Models\Team;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use RuntimeException;

class ExpoCredentialController extends Controller
{
    public function store(
        Request $request,
        Team $team,
        MobileApp $mobileApp,
        AppIdentifier $appIdentifier,
        string $credential,
        ExpoCredentialExporter $exporter,
    ): RedirectResponse {
        abort_unless(
            config('integrations.enabled') && config('integrations.expo.enabled'),
            404,
        );
        abort_unless($team->allows($request->user(), TeamPermission::ManageCredentials), 403);
        $credential = $team->credentials()->findOrFail($credential);
        abort_unless(
            $appIdentifier->mobile_app_id === $mobileApp->id
            && $this->isAttached($credential, $appIdentifier),
            404,
        );
        $project = $mobileApp->expoProject()->with('integration')->firstOrFail();
        abort_unless(
            $project->team_id === $team->id
            && $project->integration->status === IntegrationStatus::Active,
            404,
        );
        $alreadyLinked = $project->credentialLinks()
            ->where('credential_id', $credential->id)
            ->where('app_identifier_id', $appIdentifier->id)
            ->exists();

        try {
            $exporter->export($project, $appIdentifier, $credential);
        } catch (ExpoGraphqlException|RuntimeException $exception) {
            throw ValidationException::withMessages(['expo' => $exception->getMessage()]);
        }

        return back()->with(
            'status',
            $alreadyLinked ? 'Credential synced to Expo.' : 'Credential pushed to Expo.',
        );
    }

    private function isAttached(Credential $credential, AppIdentifier $identifier): bool
    {
        return $credential->appIdentifiers()->whereKey($identifier->id)->exists()
            || $credential->profiles()
                ->where('app_identifier_id', $identifier->id)
                ->exists();
    }
}

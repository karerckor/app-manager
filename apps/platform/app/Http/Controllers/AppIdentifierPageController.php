<?php

namespace App\Http\Controllers;

use App\Models\AppIdentifier;
use App\Models\MobileApp;
use App\Models\Team;
use App\Support\Apps\AppContext;
use App\Support\Apps\IdentifierPresenter;
use App\Support\Credentials\BuildProfileSchema;
use App\Support\Credentials\CredentialSchema;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class AppIdentifierPageController extends Controller
{
    public function index(Request $request, Team $team, MobileApp $mobileApp): Response
    {
        $this->authorizeTeam($request, $team);

        $mobileApp->load([
            'appIdentifiers' => fn ($q) => $q
                ->withCount(['credentials', 'profiles'])
                ->orderBy('platform')->orderBy('name'),
        ]);

        return Inertia::render('Apps/Identifiers/Index', [
            ...AppContext::for($request, $team, $mobileApp),
            'active' => 'identifiers',
            'credentialTypes' => CredentialSchema::definitions(),
            'identifiers' => $mobileApp->appIdentifiers->map(fn ($identifier) => [
                'id' => $identifier->id,
                'name' => $identifier->name,
                'platform' => $identifier->platform->value,
                'identifier' => $identifier->identifier,
                'version' => $identifier->version,
                'version_code' => $identifier->version_code,
                'credentials_count' => $identifier->credentials_count,
                'show_url' => route('accounts.apps.identifiers.show', [$team, $mobileApp, $identifier]),
                'update_url' => route('accounts.apps.identifiers.update', [$team, $mobileApp, $identifier]),
                'delete_url' => route('accounts.apps.identifiers.destroy', [$team, $mobileApp, $identifier]),
            ])->values(),
        ]);
    }

    public function show(Request $request, Team $team, MobileApp $mobileApp, AppIdentifier $appIdentifier): Response
    {
        $this->authorizeTeam($request, $team);

        $appIdentifier->load(['credentials.media', 'profiles.credentials.media']);

        return Inertia::render('Apps/Identifiers/Show', [
            ...AppContext::for($request, $team, $mobileApp),
            'active' => 'identifiers',
            'credentialTypes' => CredentialSchema::definitions(),
            'buildProfiles' => BuildProfileSchema::definitions(),
            'identifier' => IdentifierPresenter::present($team, $mobileApp, $appIdentifier),
        ]);
    }

    private function authorizeTeam(Request $request, Team $team): void
    {
        abort_unless($request->user()->teams()->whereKey($team->id)->exists(), 403);
    }
}

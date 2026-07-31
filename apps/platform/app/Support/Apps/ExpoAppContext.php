<?php

namespace App\Support\Apps;

use App\Enums\TeamPermission;
use App\Models\MobileApp;
use App\Models\Team;
use Illuminate\Http\Request;

class ExpoAppContext
{
    /** @return array<string, mixed> */
    public static function summary(Request $request, Team $team, MobileApp $app): array
    {
        $enabled = self::available($team);
        $project = $enabled
            ? $app->expoProject()
                ->whereHas('integration', fn ($query) => $query->connectedExpo())
                ->with('integration')
                ->first()
            : null;
        $connectedAccounts = $enabled
            ? $team->integrations()->activeExpo()->count()
            : 0;

        return [
            'enabled' => $enabled,
            'linked' => $project !== null,
            'connected_accounts' => $connectedAccounts,
            'can_manage' => $team->allows($request->user(), TeamPermission::ManageApplications),
            'can_manage_credentials' => $team->allows(
                $request->user(),
                TeamPermission::ManageCredentials,
            ),
            'project' => $project ? [
                'id' => $project->id,
                'name' => $project->name,
                'slug' => $project->slug,
                'full_name' => $project->full_name,
                'account_name' => $project->account_name,
                'exists_on_expo' => $project->exists_on_expo,
                'connection_status' => $project->integration->status->value,
                'url' => self::expoUrl($project->account_name, $project->slug),
            ] : null,
            'page_url' => $enabled
                ? route('accounts.apps.expo.index', [$team, $app])
                : null,
            'integrations_url' => route('accounts.integrations.eas.index', $team),
        ];
    }

    public static function available(Team $team): bool
    {
        return (bool) config('integrations.enabled')
            && (bool) config('integrations.expo.enabled')
            && $team->integrations()->connectedExpo()->exists();
    }

    public static function expoUrl(string $accountName, string $slug): string
    {
        return rtrim((string) config('integrations.expo.web_url', 'https://expo.dev'), '/')
            .'/accounts/'.rawurlencode($accountName)
            .'/projects/'.rawurlencode($slug);
    }
}

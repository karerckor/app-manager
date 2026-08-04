<?php

namespace App\Actions\Installation;

use App\Actions\Fortify\CreateNewUser;
use App\Enums\TeamRole;
use App\Models\Installation;
use App\Models\Team;
use App\Models\User;
use App\Support\Installation\SelfHostedConfiguration;
use Illuminate\Support\Facades\DB;

/**
 * Creates the very first owner of a self-hosted instance.
 *
 * Both entry points use this: the web installer and `php artisan app:bootstrap`
 * in admin mode. Keeping one transaction means the owner membership, its audit
 * row and `current_team_id` cannot drift between them.
 */
class ProvisionFirstOwner
{
    public function __construct(private readonly CreateNewUser $createNewUser) {}

    /**
     * @param  array<string, mixed>|null  $settings  Installer settings; omit to skip the installations row.
     */
    public function provision(
        string $name,
        string $email,
        string $password,
        string $teamName,
        ?string $instanceName = null,
        ?array $settings = null,
    ): User {
        return DB::transaction(function () use ($name, $email, $password, $teamName, $instanceName, $settings) {
            $user = $this->createNewUser->create([
                'name' => $name,
                'email' => $email,
                'password' => $password,
                'password_confirmation' => $password,
            ]);

            $team = Team::create([
                'name' => $teamName,
                'owner_id' => $user->id,
            ]);
            $team->addUser($user, TeamRole::Owner);
            $team->recordMembershipAudit($user, 'membership_added', TeamRole::Owner->value);
            $user->update(['current_team_id' => $team->id]);

            if ($instanceName !== null && $settings !== null) {
                Installation::create([
                    'id' => SelfHostedConfiguration::INSTALLATION_ID,
                    'name' => $instanceName,
                    'settings' => $settings,
                    'installed_by_user_id' => $user->id,
                    'installed_at' => now(),
                ]);
            }

            return $user;
        });
    }
}

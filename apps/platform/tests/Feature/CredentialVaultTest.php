<?php

namespace Tests\Feature;

use App\Enums\CredentialType;
use App\Enums\Platform;
use App\Enums\ProfileType;
use App\Models\AppIdentifier;
use App\Models\Credential;
use App\Models\MobileApp;
use App\Models\Team;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class CredentialVaultTest extends TestCase
{
    use RefreshDatabase;

    public function test_vault_lists_team_credentials_with_health_and_usages(): void
    {
        Storage::fake('public');
        $this->travelTo(now()->startOfDay());
        [$user, $team] = $this->member();
        $app = MobileApp::factory()->create(['team_id' => $team->id, 'name' => 'Atlas']);
        $ios = AppIdentifier::factory()->create([
            'mobile_app_id' => $app->id,
            'platform' => Platform::Ios,
            'identifier' => 'com.example.atlas',
        ]);
        $android = AppIdentifier::factory()->create([
            'mobile_app_id' => $app->id,
            'platform' => Platform::Android,
            'identifier' => 'com.example.atlas',
        ]);
        $profile = $ios->profiles()->create([
            'type' => ProfileType::AppStore,
            'name' => 'App Store',
        ]);

        $expired = $this->credential($team, 'Expired Credential', CredentialType::IosCertificate, now()->subDay(), true);
        $ios->credentials()->attach($expired);

        $expiring = $this->credential($team, 'Expiring Credential', CredentialType::ProvisioningProfile, now()->addDays(10), true);
        $profile->credentials()->attach($expiring);

        $this->credential($team, 'Missing Credential', CredentialType::ApnsKey, null, false);

        $ready = $this->credential($team, 'Ready Shared', CredentialType::FirebaseConfig, null, true);
        $ios->credentials()->attach($ready);
        $android->credentials()->attach($ready);

        Credential::factory()->create(['name' => 'Foreign credential']);

        $this->actingAs($user)->get(route('accounts.credentials.index', $team))
            ->assertSuccessful()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Credentials/Index')
                ->has('credentials', 4)
                ->where('stats.total', 4)
                ->where('stats.ready', 1)
                ->where('stats.attention', 3)
                ->where('stats.shared', 1)
                ->where('credentials.0.status', 'expired')
                ->where('credentials.0.usages.0.app_name', 'Atlas')
                ->where('credentials.1.status', 'expiring')
                ->where('credentials.1.usages.0.profile', ProfileType::AppStore->value)
                ->where('credentials.2.status', 'missing_file')
                ->where('credentials.2.usage_count', 0)
                ->where('credentials.3.status', 'ready')
                ->where('credentials.3.usage_count', 2));
    }

    public function test_viewer_can_read_the_vault_but_cannot_open_another_team_vault(): void
    {
        [$viewer, $team] = $this->member('viewer');
        $foreignTeam = Team::factory()->create();

        $this->actingAs($viewer)
            ->get(route('accounts.credentials.index', $team))
            ->assertSuccessful();

        $this->get(route('accounts.credentials.index', $foreignTeam))
            ->assertForbidden();
    }

    private function member(string $role = 'owner'): array
    {
        $user = User::factory()->create();
        $owner = $role === 'owner' ? $user : User::factory()->create();
        $team = Team::factory()->create(['owner_id' => $owner->id]);
        $team->addUser($user, $role);
        $user->update(['current_team_id' => $team->id]);

        return [$user->fresh(), $team];
    }

    private function credential(
        Team $team,
        string $name,
        CredentialType $type,
        mixed $expiresAt,
        bool $withFile,
    ): Credential {
        $credential = Credential::factory()->create([
            'team_id' => $team->id,
            'name' => $name,
            'type' => $type,
            'expires_at' => $expiresAt,
        ]);

        if ($withFile) {
            $credential->addMediaFromString($name)
                ->usingFileName(str($name)->slug().'.bin')
                ->toMediaCollection('file');
        }

        return $credential;
    }
}

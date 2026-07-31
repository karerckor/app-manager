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
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class CredentialReuseTest extends TestCase
{
    use RefreshDatabase;

    public function test_identifier_page_offers_compatible_team_credentials_from_the_vault(): void
    {
        [$user, $team, $app, $identifier] = $this->application(Platform::Ios);
        $available = Credential::factory()->create([
            'team_id' => $team->id,
            'type' => CredentialType::ApnsKey,
            'name' => 'Shared push key',
        ]);
        Credential::factory()->create([
            'team_id' => $team->id,
            'type' => CredentialType::AndroidKeystore,
            'name' => 'Android only',
        ]);
        Credential::factory()->create([
            'type' => CredentialType::ApnsKey,
            'name' => 'Other team key',
        ]);

        $this->actingAs($user)
            ->get(route('accounts.apps.identifiers.show', [$team, $app, $identifier]))
            ->assertSuccessful()
            ->assertInertia(fn (Assert $page) => $page
                ->where(
                    'identifier.attach_credential_url',
                    route('accounts.apps.identifiers.credentials.attach', [$team, $app, $identifier]),
                )
                ->has('availableCredentials', 1)
                ->where('availableCredentials.0.id', $available->id)
                ->where('availableCredentials.0.type', CredentialType::ApnsKey->value));
    }

    public function test_existing_vault_credential_is_reused_without_creating_a_copy(): void
    {
        [$user, $team, $app, $identifier] = $this->application(Platform::Ios);
        $credential = Credential::factory()->create([
            'team_id' => $team->id,
            'type' => CredentialType::ApnsKey,
        ]);

        $this->actingAs($user)
            ->post($this->attachUrl($team, $app, $identifier), [
                'credential_id' => $credential->id,
            ])
            ->assertRedirect()
            ->assertSessionHasNoErrors()
            ->assertSessionHas('status', 'Credential reused from Credentials Vault.');

        $this->assertDatabaseCount('credentials', 1);
        $this->assertTrue($identifier->credentials()->whereKey($credential->id)->exists());
        $this->assertSame($credential->id, $identifier->credentials()->sole()->id);
    }

    public function test_existing_signing_credential_is_reused_on_the_selected_build_target(): void
    {
        [$user, $team, $app, $identifier] = $this->application(Platform::Ios);
        $credential = Credential::factory()->create([
            'team_id' => $team->id,
            'type' => CredentialType::IosCertificate,
        ]);

        $this->actingAs($user)
            ->post($this->attachUrl($team, $app, $identifier), [
                'credential_id' => $credential->id,
                'profile_type' => ProfileType::AppStore->value,
            ])
            ->assertRedirect()
            ->assertSessionHasNoErrors();

        $profile = $identifier->profiles()->where('type', ProfileType::AppStore)->sole();

        $this->assertDatabaseCount('credentials', 1);
        $this->assertTrue($profile->credentials()->whereKey($credential->id)->exists());
        $this->assertFalse($identifier->credentials()->whereKey($credential->id)->exists());
    }

    public function test_credential_from_another_team_cannot_be_reused(): void
    {
        [$user, $team, $app, $identifier] = $this->application(Platform::Ios);
        $foreignCredential = Credential::factory()->create([
            'type' => CredentialType::ApnsKey,
        ]);

        $this->actingAs($user)
            ->post($this->attachUrl($team, $app, $identifier), [
                'credential_id' => $foreignCredential->id,
            ])
            ->assertSessionHasErrors('credential_id');

        $this->assertFalse($identifier->credentials()->whereKey($foreignCredential->id)->exists());
    }

    public function test_incompatible_vault_credential_cannot_be_reused(): void
    {
        [$user, $team, $app, $identifier] = $this->application(Platform::Ios);
        $credential = Credential::factory()->create([
            'team_id' => $team->id,
            'type' => CredentialType::AndroidKeystore,
        ]);

        $this->actingAs($user)
            ->post($this->attachUrl($team, $app, $identifier), [
                'credential_id' => $credential->id,
                'profile_type' => ProfileType::Production->value,
            ])
            ->assertSessionHasErrors('credential_id');

        $this->assertDatabaseCount('credentialables', 0);
    }

    public function test_credential_cannot_be_reused_through_an_identifier_from_another_app(): void
    {
        [$user, $team, $app] = $this->application(Platform::Ios);
        $otherApp = MobileApp::factory()->create(['team_id' => $team->id]);
        $otherIdentifier = AppIdentifier::factory()->create([
            'mobile_app_id' => $otherApp->id,
            'platform' => Platform::Ios,
        ]);
        $credential = Credential::factory()->create([
            'team_id' => $team->id,
            'type' => CredentialType::ApnsKey,
        ]);

        $this->actingAs($user)
            ->post($this->attachUrl($team, $app, $otherIdentifier), [
                'credential_id' => $credential->id,
            ])
            ->assertNotFound();

        $this->assertDatabaseCount('credentialables', 0);
    }

    private function application(Platform $platform): array
    {
        $user = User::factory()->create();
        $team = Team::factory()->create(['owner_id' => $user->id]);
        $team->users()->attach($user, ['role' => 'owner']);
        $user->update(['current_team_id' => $team->id]);
        $app = MobileApp::factory()->create(['team_id' => $team->id]);
        $identifier = AppIdentifier::factory()->create([
            'mobile_app_id' => $app->id,
            'platform' => $platform,
        ]);

        return [$user->fresh(), $team, $app, $identifier];
    }

    private function attachUrl(Team $team, MobileApp $app, AppIdentifier $identifier): string
    {
        return route('accounts.apps.identifiers.credentials.attach', [$team, $app, $identifier]);
    }
}

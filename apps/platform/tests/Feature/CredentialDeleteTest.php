<?php

namespace Tests\Feature;

use App\Enums\CredentialType;
use App\Enums\Platform;
use App\Enums\WebhookEventType;
use App\Models\AppIdentifier;
use App\Models\Credential;
use App\Models\MobileApp;
use App\Models\Team;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CredentialDeleteTest extends TestCase
{
    use RefreshDatabase;

    public function test_team_member_can_delete_a_credential_and_records_event(): void
    {
        [$user, $team, $app] = $this->application();
        $identifier = $this->identifier($app, 'com.example.app');
        $credential = Credential::factory()->create([
            'team_id' => $team->id,
            'type' => CredentialType::GooglePlayServiceAccount,
            'name' => 'Play account',
        ]);
        $identifier->credentials()->attach($credential);

        $this->actingAs($user)
            ->delete(route('accounts.credentials.destroy', [$team, $credential]))
            ->assertRedirect()
            ->assertSessionHas('status', 'Credential removed.');

        $this->assertDatabaseMissing('credentials', ['id' => $credential->id]);
        $this->assertDatabaseHas('webhook_events', [
            'team_id' => $team->id,
            'event_type' => WebhookEventType::CredentialDeleted->value,
            'aggregate_id' => $credential->id,
        ]);
    }

    public function test_credential_from_another_team_cannot_be_deleted(): void
    {
        [$user, $team] = $this->application();
        $otherTeam = Team::factory()->create();
        $credential = Credential::factory()->create([
            'team_id' => $otherTeam->id,
            'type' => CredentialType::GooglePlayServiceAccount,
            'name' => 'Foreign account',
        ]);

        $this->actingAs($user)
            ->delete(route('accounts.credentials.destroy', [$team, $credential]))
            ->assertNotFound();

        $this->assertDatabaseHas('credentials', ['id' => $credential->id]);
    }

    private function application(): array
    {
        $user = User::factory()->create();
        $team = Team::factory()->create(['owner_id' => $user->id]);
        $team->users()->attach($user, ['role' => 'owner']);
        $user->update(['current_team_id' => $team->id]);
        $app = MobileApp::factory()->create(['team_id' => $team->id]);

        return [$user->fresh(), $team, $app];
    }

    private function identifier(MobileApp $app, string $identifier): AppIdentifier
    {
        return AppIdentifier::factory()->create([
            'mobile_app_id' => $app->id,
            'platform' => Platform::Android,
            'identifier' => $identifier,
        ]);
    }
}

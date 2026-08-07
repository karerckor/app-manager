<?php

namespace Tests\Feature;

use App\Enums\Platform;
use App\Enums\WebhookEventType;
use App\Models\AppIdentifier;
use App\Models\MobileApp;
use App\Models\Team;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AppIdentifierDeleteTest extends TestCase
{
    use RefreshDatabase;

    public function test_team_member_can_delete_an_identifier_and_records_event(): void
    {
        [$user, $team, $app] = $this->application();
        $identifier = AppIdentifier::factory()->create([
            'mobile_app_id' => $app->id,
            'platform' => Platform::Ios,
            'identifier' => 'com.example.app',
        ]);

        $this->actingAs($user)
            ->delete(route('accounts.apps.identifiers.destroy', [$team, $app, $identifier]))
            ->assertRedirect()
            ->assertSessionHas('status', 'App identifier removed.');

        $this->assertSoftDeleted('app_identifiers', ['id' => $identifier->id]);
        $this->assertDatabaseHas('webhook_events', [
            'team_id' => $team->id,
            'event_type' => WebhookEventType::AppIdentifierDeleted->value,
            'aggregate_id' => $identifier->id,
        ]);
    }

    public function test_non_member_cannot_delete_an_identifier(): void
    {
        [, $team, $app] = $this->application();
        $identifier = AppIdentifier::factory()->create([
            'mobile_app_id' => $app->id,
            'platform' => Platform::Ios,
            'identifier' => 'com.example.app',
        ]);
        $stranger = User::factory()->create();

        $this->actingAs($stranger)
            ->delete(route('accounts.apps.identifiers.destroy', [$team, $app, $identifier]))
            ->assertForbidden();

        $this->assertDatabaseHas('app_identifiers', ['id' => $identifier->id]);
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
}

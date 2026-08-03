<?php

namespace Tests\Feature\Tags;

use App\Enums\WebhookEventType;
use App\Models\AppIdentifier;
use App\Models\Artifact;
use App\Models\BuildRecord;
use App\Models\Credential;
use App\Models\MobileApp;
use App\Models\Team;
use App\Models\User;
use App\Models\WebhookEvent;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class TagsEndpointTest extends TestCase
{
    use RefreshDatabase;

    /** @return array{0: User, 1: Team} */
    private function member(): array
    {
        $user = User::factory()->create();
        $team = Team::factory()->create(['owner_id' => $user->id]);
        $team->users()->attach($user, ['role' => 'owner']);
        $user->update(['current_team_id' => $team->id]);

        return [$user, $team];
    }

    public function test_updates_app_tags(): void
    {
        [$user, $team] = $this->member();
        $app = MobileApp::factory()->create(['team_id' => $team->id]);

        $this->actingAs($user)
            ->patch("/accounts/{$team->slug}/apps/{$app->slug}/tags", [
                'tags' => [['bug', '#FF0000'], ['urgent', null]],
            ])
            ->assertRedirect();

        $this->assertSame([['bug', '#ff0000'], ['urgent']], $app->fresh()->tags);
    }

    public function test_app_tag_update_records_webhook_event(): void
    {
        config(['integrations.enabled' => true, 'integrations.webhooks.enabled' => true]);
        [$user, $team] = $this->member();
        $app = MobileApp::factory()->create(['team_id' => $team->id]);

        $this->actingAs($user)
            ->patch("/accounts/{$team->slug}/apps/{$app->slug}/tags", [
                'tags' => [['bug', null]],
            ])
            ->assertRedirect();

        $this->assertTrue(WebhookEvent::query()->where('event_type', WebhookEventType::MobileAppUpdated)->exists());
    }

    public function test_updates_credential_tags(): void
    {
        [$user, $team] = $this->member();
        $credential = Credential::factory()->create(['team_id' => $team->id]);

        $this->actingAs($user)
            ->patch("/accounts/{$team->slug}/credentials/{$credential->id}/tags", [
                'tags' => [['prod', '#00ff00']],
            ])
            ->assertRedirect();

        $this->assertSame([['prod', '#00ff00']], $credential->fresh()->tags);
    }

    public function test_updates_build_and_artifact_tags(): void
    {
        [$user, $team] = $this->member();
        $app = MobileApp::factory()->create(['team_id' => $team->id]);
        $identifier = AppIdentifier::factory()->create(['mobile_app_id' => $app->id]);
        $build = BuildRecord::factory()->create([
            'team_id' => $team->id,
            'mobile_app_id' => $app->id,
            'app_identifier_id' => $identifier->id,
        ]);
        $artifact = Artifact::factory()->for($build)->create(['team_id' => $team->id]);

        $this->actingAs($user)
            ->patch("/accounts/{$team->slug}/apps/{$app->slug}/builds/{$build->id}/tags", [
                'tags' => [['nightly', null]],
            ])->assertRedirect();
        $this->assertSame([['nightly']], $build->fresh()->tags);

        $this->actingAs($user)
            ->patch("/accounts/{$team->slug}/apps/{$app->slug}/builds/{$build->id}/artifacts/{$artifact->id}/tags", [
                'tags' => [['signed', '#123abc']],
            ])->assertRedirect();
        $this->assertSame([['signed', '#123abc']], $artifact->fresh()->tags);
    }

    public function test_build_tag_update_records_no_webhook_event(): void
    {
        config(['integrations.enabled' => true, 'integrations.webhooks.enabled' => true]);
        [$user, $team] = $this->member();
        $app = MobileApp::factory()->create(['team_id' => $team->id]);
        $identifier = AppIdentifier::factory()->create(['mobile_app_id' => $app->id]);
        $build = BuildRecord::factory()->create([
            'team_id' => $team->id,
            'mobile_app_id' => $app->id,
            'app_identifier_id' => $identifier->id,
        ]);

        $this->actingAs($user)
            ->patch("/accounts/{$team->slug}/apps/{$app->slug}/builds/{$build->id}/tags", [
                'tags' => [['nightly', null]],
            ])->assertRedirect();

        $this->assertSame(0, WebhookEvent::query()->count());
    }

    public function test_rejects_invalid_hex(): void
    {
        [$user, $team] = $this->member();
        $app = MobileApp::factory()->create(['team_id' => $team->id]);

        $this->actingAs($user)
            ->patch("/accounts/{$team->slug}/apps/{$app->slug}/tags", [
                'tags' => [['bug', 'red']],
            ])
            ->assertSessionHasErrors('tags.0.1');

        $this->assertSame([], $app->fresh()->tagList());
    }

    public function test_rejects_empty_text(): void
    {
        [$user, $team] = $this->member();
        $app = MobileApp::factory()->create(['team_id' => $team->id]);

        $this->actingAs($user)
            ->patch("/accounts/{$team->slug}/apps/{$app->slug}/tags", [
                'tags' => [['', '#ffffff']],
            ])
            ->assertSessionHasErrors('tags.0.0');
    }

    public function test_cannot_tag_app_from_another_team(): void
    {
        [$user, $team] = $this->member();
        $foreignApp = MobileApp::factory()->create(['team_id' => Team::factory()->create()->id]);

        $this->actingAs($user)
            ->patch("/accounts/{$team->slug}/apps/{$foreignApp->slug}/tags", [
                'tags' => [['x', null]],
            ])
            ->assertNotFound();
    }
}

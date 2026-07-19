<?php

namespace Tests\Feature\Tags;

use App\Http\Resources\Api\V1\ArtifactResource;
use App\Http\Resources\Api\V1\BuildRecordResource;
use App\Http\Resources\Api\V1\CredentialResource;
use App\Http\Resources\Api\V1\MobileAppResource;
use App\Models\AppIdentifier;
use App\Models\Artifact;
use App\Models\BuildRecord;
use App\Models\Credential;
use App\Models\MobileApp;
use App\Models\Team;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class TagSerializationTest extends TestCase
{
    use RefreshDatabase;

    public function test_api_resources_expose_tags(): void
    {
        $app = MobileApp::factory()->create();
        $app->syncTags([['a', null]])->save();
        $this->assertSame([['a', null]], (new MobileAppResource($app))->toArray(request())['tags']);
        $this->assertArrayHasKey('tags', (new MobileAppResource($app))->toArray(request()));

        $credential = Credential::factory()->create();
        $credential->syncTags([['b', '#ffffff']])->save();
        $this->assertSame([['b', '#ffffff']], (new CredentialResource($credential))->toArray(request())['tags']);

        $build = BuildRecord::factory()->create();
        $build->syncTags([['c', null]])->save();
        $this->assertSame([['c', null]], (new BuildRecordResource($build))->toArray(request())['tags']);

        $artifact = Artifact::factory()->create();
        $artifact->syncTags([['d', null]])->save();
        $this->assertSame([['d', null]], (new ArtifactResource($artifact))->toArray(request())['tags']);
    }

    public function test_show_page_exposes_tags_and_urls(): void
    {
        $user = User::factory()->create();
        $team = Team::factory()->create(['owner_id' => $user->id]);
        $team->users()->attach($user, ['role' => 'owner']);
        $user->update(['current_team_id' => $team->id]);

        $app = MobileApp::factory()->create(['team_id' => $team->id]);
        $app->syncTags([['flagship', '#abcdef']])->save();
        $identifier = AppIdentifier::factory()->create(['mobile_app_id' => $app->id]);
        $build = BuildRecord::factory()->create([
            'team_id' => $team->id,
            'mobile_app_id' => $app->id,
            'app_identifier_id' => $identifier->id,
        ]);
        $build->syncTags([['nightly', null]])->save();

        $this->actingAs($user)
            ->get("/accounts/{$team->slug}/apps/{$app->slug}")
            ->assertInertia(fn (Assert $page) => $page
                ->where('app.tags.0.0', 'flagship')
                ->has('app.urls.tags')
                ->where('builds.0.tags.0.0', 'nightly')
                ->has('builds.0.tags_url'));
    }
}

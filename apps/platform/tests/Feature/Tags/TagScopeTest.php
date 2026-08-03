<?php

namespace Tests\Feature\Tags;

use App\Models\MobileApp;
use App\Models\Team;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class TagScopeTest extends TestCase
{
    use RefreshDatabase;

    public function test_where_tag_matches_by_text_only(): void
    {
        $team = Team::factory()->create();
        $tagged = MobileApp::factory()->create(['team_id' => $team->id]);
        $tagged->syncTags([['release', '#00ff00']])->save();
        $other = MobileApp::factory()->create(['team_id' => $team->id]);
        $other->syncTags([['bug', null]])->save();
        $untagged = MobileApp::factory()->create(['team_id' => $team->id]);

        $ids = MobileApp::whereTag('release')->pluck('id');

        $this->assertTrue($ids->contains($tagged->id));
        $this->assertFalse($ids->contains($other->id));
        $this->assertFalse($ids->contains($untagged->id));
    }

    public function test_where_any_tag_matches_multiple(): void
    {
        $team = Team::factory()->create();
        $a = MobileApp::factory()->create(['team_id' => $team->id]);
        $a->syncTags([['bug', null]])->save();
        $b = MobileApp::factory()->create(['team_id' => $team->id]);
        $b->syncTags([['release', null]])->save();
        $c = MobileApp::factory()->create(['team_id' => $team->id]);
        $c->syncTags([['chore', null]])->save();

        $ids = MobileApp::whereAnyTag(['bug', 'release'])->pluck('id');

        $this->assertTrue($ids->contains($a->id));
        $this->assertTrue($ids->contains($b->id));
        $this->assertFalse($ids->contains($c->id));
    }

    public function test_where_tag_can_compose_with_other_constraints(): void
    {
        $team = Team::factory()->create();
        $match = MobileApp::factory()->create(['team_id' => $team->id]);
        $match->syncTags([['bug', null]])->save();
        $otherTeam = MobileApp::factory()->create(['team_id' => Team::factory()->create()->id]);
        $otherTeam->syncTags([['bug', null]])->save();

        $ids = MobileApp::where('team_id', $team->id)->whereTag('bug')->pluck('id');

        $this->assertTrue($ids->contains($match->id));
        $this->assertFalse($ids->contains($otherTeam->id));
    }

    public function test_where_all_tags_requires_every_text(): void
    {
        $team = Team::factory()->create();
        $both = MobileApp::factory()->create(['team_id' => $team->id]);
        $both->syncTags([['beta', null], ['qa', '#fff']])->save();
        $one = MobileApp::factory()->create(['team_id' => $team->id]);
        $one->syncTags([['beta', null]])->save();

        $ids = MobileApp::whereAllTags(['beta', 'qa'])->pluck('id');

        $this->assertTrue($ids->contains($both->id));
        $this->assertFalse($ids->contains($one->id));
    }

    public function test_where_tag_like_matches_partial_text_case_insensitively(): void
    {
        $team = Team::factory()->create();
        $match = MobileApp::factory()->create(['team_id' => $team->id]);
        $match->syncTags([['Onboarding', null]])->save();
        $miss = MobileApp::factory()->create(['team_id' => $team->id]);
        $miss->syncTags([['release', null]])->save();

        $ids = MobileApp::whereTagLike('%onb%')->pluck('id');

        $this->assertTrue($ids->contains($match->id));
        $this->assertFalse($ids->contains($miss->id));
    }

    public function test_where_tag_like_ignores_color_and_animation(): void
    {
        $team = Team::factory()->create();
        $app = MobileApp::factory()->create(['team_id' => $team->id]);
        $app->syncTags([['beta', '#10b981', 'pulse']])->save();

        $this->assertFalse(MobileApp::whereTagLike('%10b981%')->pluck('id')->contains($app->id));
        $this->assertFalse(MobileApp::whereTagLike('%pulse%')->pluck('id')->contains($app->id));
        $this->assertTrue(MobileApp::whereTagLike('%bet%')->pluck('id')->contains($app->id));
    }

    public function test_new_scopes_tolerate_a_null_tags_column(): void
    {
        $team = Team::factory()->create();
        $untagged = MobileApp::factory()->create(['team_id' => $team->id]);

        $this->assertFalse(MobileApp::whereAllTags(['beta'])->pluck('id')->contains($untagged->id));
        $this->assertFalse(MobileApp::whereTagLike('%beta%')->pluck('id')->contains($untagged->id));
    }
}

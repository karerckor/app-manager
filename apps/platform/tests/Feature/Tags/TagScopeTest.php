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
}

<?php

namespace Tests\Feature\Tags;

use App\Models\AppIdentifier;
use App\Models\Audit;
use App\Models\BuildRecord;
use App\Models\MobileApp;
use App\Models\Team;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class TagSuggestionShapeTest extends TestCase
{
    use RefreshDatabase;

    public function test_app_context_suggestions_keep_the_animation(): void
    {
        [$user, $team] = $this->context();

        $app = MobileApp::factory()->create(['team_id' => $team->id]);
        $app->syncTags([['flagship', '#abcdef', 'tada']])->save();

        $this->actingAs($user)
            ->get("/accounts/{$team->slug}/apps/{$app->slug}")
            ->assertInertia(fn (Assert $page) => $page
                ->where('tag_suggestions.0.0', 'flagship')
                ->where('tag_suggestions.0.1', '#abcdef')
                ->where('tag_suggestions.0.2', 'tada'));
    }

    public function test_app_context_suggestions_stay_compact_without_an_animation(): void
    {
        [$user, $team] = $this->context();

        $app = MobileApp::factory()->create(['team_id' => $team->id]);
        $app->syncTags([['plain', null]])->save();

        $this->actingAs($user)
            ->get("/accounts/{$team->slug}/apps/{$app->slug}")
            ->assertInertia(fn (Assert $page) => $page
                ->where('tag_suggestions.0', ['plain']));
    }

    public function test_build_page_filter_tags_keep_the_animation(): void
    {
        [$user, $team] = $this->context();

        $app = MobileApp::factory()->create(['team_id' => $team->id]);
        $identifier = AppIdentifier::factory()->create(['mobile_app_id' => $app->id]);
        $build = BuildRecord::factory()->create([
            'team_id' => $team->id,
            'mobile_app_id' => $app->id,
            'app_identifier_id' => $identifier->id,
        ]);
        $build->syncTags([['nightly', null, 'pulse']])->save();

        $this->actingAs($user)
            ->get(route('accounts.apps.builds.index', [$team, $app]))
            ->assertInertia(fn (Assert $page) => $page
                ->where('filterTags.0.0', 'nightly')
                ->where('filterTags.0.2', 'pulse'));
    }

    public function test_audit_diff_surfaces_the_animation(): void
    {
        [$user, $team] = $this->context();
        $app = MobileApp::factory()->create(['team_id' => $team->id]);

        Audit::create([
            'event' => 'updated',
            'auditable_type' => MobileApp::class,
            'auditable_id' => $app->id,
            'old_values' => ['tags' => '[["beta","#10b981"]]'],
            'new_values' => ['tags' => '[["beta","#10b981","pulse"]]'],
            'team_id' => $team->id,
            'source' => 'web',
        ]);

        $this->actingAs($user)
            ->get(route('accounts.audit.index', $team))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('audits.data.0.changes.0.from_tags', [
                    ['label' => 'beta', 'color' => '#10b981', 'animation' => null],
                ])
                ->where('audits.data.0.changes.0.to_tags', [
                    ['label' => 'beta', 'color' => '#10b981', 'animation' => 'pulse'],
                ]));
    }

    /** @return array{User, Team} */
    private function context(): array
    {
        $user = User::factory()->create();
        $team = Team::factory()->create(['owner_id' => $user->id]);
        $team->users()->attach($user, ['role' => 'owner']);
        $user->update(['current_team_id' => $team->id]);

        return [$user, $team];
    }
}

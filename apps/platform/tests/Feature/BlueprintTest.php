<?php

namespace Tests\Feature;

use App\Enums\BlueprintHookAction;
use App\Enums\BlueprintHookCondition;
use App\Enums\Platform;
use App\Models\Blueprint;
use App\Models\ExpoProject;
use App\Models\Integration;
use App\Models\MobileApp;
use App\Models\Team;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class BlueprintTest extends TestCase
{
    use RefreshDatabase;

    public function test_blueprint_can_be_saved_as_an_incomplete_draft(): void
    {
        [$user, $team] = $this->userWithTeam();

        $this->actingAs($user)->post(route('accounts.blueprints.store', $team), [
            'name' => 'Production mobile app',
            'description' => 'Shared application contract.',
            'json_schema' => null,
            'required_files' => [],
            'platforms' => [],
        ])->assertRedirect()
            ->assertSessionHas('status', 'Blueprint created.');

        $blueprint = Blueprint::query()->sole();

        $this->assertSame('Production mobile app', $blueprint->name);
        $this->assertNull($blueprint->json_schema);
        $this->assertSame([], $blueprint->required_files);
        $this->assertSame([], $blueprint->platforms);

        $this->get(route('accounts.blueprints.index', $team))
            ->assertSuccessful()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Blueprints/Index')
                ->where('active', 'blueprints')
                ->where('blueprints.0.name', 'Production mobile app')
                ->has('conditionOptions', 8)
                ->has('actionOptions', 2));
    }

    public function test_application_can_be_attached_to_and_detached_from_a_blueprint_without_being_complete(): void
    {
        [$user, $team] = $this->userWithTeam();
        $blueprint = Blueprint::factory()->create([
            'team_id' => $team->id,
            'json_schema' => ['type' => 'object', 'required' => ['owner']],
            'required_files' => ['associated-domains.json'],
            'platforms' => [Platform::Ios->value],
        ]);
        $app = MobileApp::factory()->create(['team_id' => $team->id, 'metadata' => null]);

        $this->actingAs($user)->patch(route('accounts.apps.update', [$team, $app]), [
            'name' => $app->name,
            'description' => $app->description,
            'metadata' => null,
            'blueprint_id' => $blueprint->id,
        ])->assertRedirect();

        $this->assertSame($blueprint->id, $app->fresh()->blueprint_id);

        $this->get(route('accounts.apps.show', [$team, $app]))
            ->assertInertia(fn (Assert $page) => $page
                ->where('app.blueprint.id', $blueprint->id)
                ->where('app.blueprint.status.matches', false)
                ->where('app.blueprint.status.metadata_matches', false)
                ->where('app.blueprint.status.missing_files', ['associated-domains.json'])
                ->where('app.blueprint.status.missing_platforms', ['ios']));

        $this->patch(route('accounts.apps.update', [$team, $app]), [
            'name' => $app->name,
            'blueprint_id' => null,
        ])->assertRedirect();

        $this->assertNull($app->fresh()->blueprint_id);
    }

    public function test_blueprint_can_require_an_application_to_be_in_or_out_of_expo(): void
    {
        [$user, $team] = $this->userWithTeam();
        $blueprint = Blueprint::factory()->create([
            'team_id' => $team->id,
            'requires_expo' => true,
        ]);
        $app = MobileApp::factory()->create([
            'team_id' => $team->id,
            'blueprint_id' => $blueprint->id,
        ]);

        $this->actingAs($user)->get(route('accounts.apps.show', [$team, $app]))
            ->assertInertia(fn (Assert $page) => $page
                ->where('app.blueprint.status.is_in_expo', false)
                ->where('app.blueprint.status.expo_matches', false)
                ->where('app.blueprint.status.matches', false));

        $integration = Integration::factory()->create(['team_id' => $team->id]);
        ExpoProject::create([
            'team_id' => $team->id,
            'integration_id' => $integration->id,
            'mobile_app_id' => $app->id,
            'external_id' => 'expo-project',
            'account_id' => 'account-1',
            'account_name' => 'acme',
            'slug' => 'mobile-app',
            'name' => 'Mobile app',
            'full_name' => '@acme/mobile-app',
            'exists_on_expo' => true,
        ]);

        $this->actingAs($user)->get(route('accounts.apps.show', [$team, $app]))
            ->assertInertia(fn (Assert $page) => $page
                ->where('app.blueprint.status.is_in_expo', true)
                ->where('app.blueprint.status.expo_matches', true)
                ->where('app.blueprint.status.matches', true));

        $this->actingAs($user)->patch(route('accounts.blueprints.update', [$team, $blueprint]), [
            'requires_expo' => false,
        ])->assertRedirect();

        $this->actingAs($user)->get(route('accounts.apps.show', [$team, $app]))
            ->assertInertia(fn (Assert $page) => $page
                ->where('app.blueprint.status.expo_matches', false)
                ->where('app.blueprint.status.matches', false));
    }

    public function test_attached_applications_are_paginated_and_searchable_by_name_or_slug(): void
    {
        [$user, $team] = $this->userWithTeam();
        $blueprint = Blueprint::factory()->create(['team_id' => $team->id]);

        foreach (range(1, 27) as $number) {
            MobileApp::factory()->create([
                'team_id' => $team->id,
                'blueprint_id' => $blueprint->id,
                'name' => sprintf('Application %02d', $number),
                'slug' => sprintf('application-%02d', $number),
            ]);
        }

        MobileApp::factory()->create([
            'team_id' => $team->id,
            'blueprint_id' => $blueprint->id,
            'name' => 'Release companion',
            'slug' => 'needle-slug',
        ]);

        $this->actingAs($user)->get(route('accounts.blueprints.index', [
            'team' => $team,
            'blueprint' => $blueprint->id,
            'apps_page' => 2,
        ]))
            ->assertSuccessful()
            ->assertInertia(fn (Assert $page) => $page
                ->where('selectedBlueprintId', $blueprint->id)
                ->where('blueprints.0.apps_count', 28)
                ->where('applications.current_page', 2)
                ->where('applications.total', 28)
                ->has('applications.data', 3));

        $this->actingAs($user)->get(route('accounts.blueprints.index', [
            'team' => $team,
            'blueprint' => $blueprint->id,
            'apps_search' => 'needle-slug',
        ]))
            ->assertSuccessful()
            ->assertInertia(fn (Assert $page) => $page
                ->where('applicationFilters.search', 'needle-slug')
                ->where('applications.total', 1)
                ->where('applications.data.0.slug', 'needle-slug'));
    }

    public function test_conditional_hooks_add_and_remove_tags_as_application_becomes_compliant(): void
    {
        Storage::fake(config('media-library.disk_name'));
        [$user, $team] = $this->userWithTeam();
        $blueprint = Blueprint::factory()->create([
            'team_id' => $team->id,
            'json_schema' => [
                'type' => 'object',
                'required' => ['owner'],
                'properties' => ['owner' => ['type' => 'string', 'minLength' => 1]],
            ],
            'required_files' => ['associated-domains.json'],
            'platforms' => [Platform::Ios->value],
        ]);
        $blueprint->hooks()->create([
            'name' => 'Flag incomplete apps',
            'condition' => BlueprintHookCondition::BlueprintMismatch,
            'actions' => [
                [
                    'type' => BlueprintHookAction::AddTag->value,
                    'tags' => [
                        ['blueprint-incomplete', '#a87524', 'pulse'],
                        ['needs-review', '#f43f5e', 'heartbeat'],
                    ],
                ],
            ],
            'enabled' => true,
        ]);
        $blueprint->hooks()->create([
            'name' => 'Clear completed apps',
            'condition' => BlueprintHookCondition::BlueprintMatches,
            'actions' => [[
                'type' => BlueprintHookAction::RemoveTag->value,
                'tags' => [['blueprint-incomplete'], ['needs-review']],
            ]],
            'enabled' => true,
        ]);
        $app = MobileApp::factory()->create([
            'team_id' => $team->id,
            'blueprint_id' => $blueprint->id,
            'metadata' => null,
        ]);

        $this->actingAs($user)->post(route('accounts.blueprints.evaluate', [$team, $blueprint]));
        $this->assertTrue($app->fresh()->hasTag('blueprint-incomplete'));
        $this->assertSame([
            ['blueprint-incomplete', '#a87524', 'pulse'],
            ['needs-review', '#f43f5e', 'heartbeat'],
        ], $app->fresh()->tags);

        $this->patch(route('accounts.apps.update', [$team, $app]), [
            'name' => $app->name,
            'metadata' => ['owner' => 'mobile-team'],
        ])->assertRedirect();
        $this->assertTrue($app->fresh()->hasTag('blueprint-incomplete'));

        $this->post(route('accounts.apps.media.files.store', [$team, $app]), [
            'file' => UploadedFile::fake()->create('associated-domains.json', 2, 'application/json'),
        ])->assertRedirect();
        $this->assertTrue($app->fresh()->hasTag('blueprint-incomplete'));

        $this->post(route('accounts.apps.identifiers.store', [$team, $app]), [
            'platform' => Platform::Ios->value,
            'identifier' => 'com.example.blueprint',
            'name' => 'iOS',
        ])->assertRedirect();

        $this->assertFalse($app->fresh()->hasTag('blueprint-incomplete'));
        $this->assertFalse($app->fresh()->hasTag('needs-review'));
    }

    public function test_file_hook_requires_an_exact_file_declared_by_the_blueprint(): void
    {
        [$user, $team] = $this->userWithTeam();
        $blueprint = Blueprint::factory()->create([
            'team_id' => $team->id,
            'required_files' => ['associated-domains.json'],
        ]);

        $this->actingAs($user)->post(route('accounts.blueprints.hooks.store', [$team, $blueprint]), [
            'name' => 'Missing file',
            'condition' => BlueprintHookCondition::FileMissing->value,
            'condition_config' => ['file_name' => 'other.json'],
            'actions' => [[
                'type' => BlueprintHookAction::AddTag->value,
                'tags' => [['missing-file']],
            ]],
            'enabled' => true,
        ])->assertSessionHasErrors('condition_config.file_name');

        $this->assertCount(0, $blueprint->hooks);
    }

    public function test_one_condition_can_run_add_and_remove_tag_actions_in_order(): void
    {
        [$user, $team] = $this->userWithTeam();
        $blueprint = Blueprint::factory()->create(['team_id' => $team->id]);
        $actions = [
            [
                'type' => BlueprintHookAction::RemoveTag->value,
                'tags' => [['blueprint-incomplete']],
            ],
            [
                'type' => BlueprintHookAction::AddTag->value,
                'tags' => [['blueprint-ready', '#10b981', 'pulse']],
            ],
        ];

        $this->actingAs($user)->post(route('accounts.blueprints.hooks.store', [$team, $blueprint]), [
            'name' => 'Mark a matching app ready',
            'condition' => BlueprintHookCondition::BlueprintMatches->value,
            'actions' => $actions,
            'enabled' => true,
        ])->assertRedirect()
            ->assertSessionHas('status', 'Conditional hook created and evaluated.');

        $this->assertSame($actions, $blueprint->hooks()->sole()->actions);
        $app = MobileApp::factory()->create([
            'team_id' => $team->id,
            'blueprint_id' => $blueprint->id,
            'tags' => [['blueprint-incomplete', '#a87524']],
        ]);

        $this->actingAs($user)->post(route('accounts.blueprints.evaluate', [$team, $blueprint]))
            ->assertRedirect();

        $this->assertSame(
            [['blueprint-ready', '#10b981', 'pulse']],
            $app->fresh()->tags,
        );
    }

    private function userWithTeam(): array
    {
        $user = User::factory()->create();
        $team = Team::factory()->create(['owner_id' => $user->id]);
        $team->users()->attach($user, ['role' => 'owner']);
        $user->update(['current_team_id' => $team->id]);

        return [$user->fresh(), $team];
    }
}

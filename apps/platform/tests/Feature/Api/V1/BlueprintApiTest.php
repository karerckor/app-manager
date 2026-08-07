<?php

namespace Tests\Feature\Api\V1;

use App\Enums\ApiKeyScope;
use App\Enums\BlueprintHookAction;
use App\Enums\BlueprintHookCondition;
use App\Enums\Platform;
use App\Models\Blueprint;
use App\Models\MobileApp;
use App\Models\Team;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class BlueprintApiTest extends TestCase
{
    use RefreshDatabase;

    public function test_blueprints_and_conditional_hooks_can_be_managed_through_the_api(): void
    {
        [$user, $team] = $this->teamOwner();
        $this->authenticate($user, $team, [ApiKeyScope::BlueprintsRead, ApiKeyScope::BlueprintsWrite]);

        $created = $this->postJson($this->api($team, '/blueprints'), [
            'name' => 'Production app',
            'description' => '  Shared application contract.  ',
            'json_schema' => [
                'type' => 'object',
                'required' => ['owner'],
            ],
            'required_files' => [' associated-domains.json '],
            'platforms' => [Platform::Ios->value],
            'requires_expo' => true,
        ])->assertCreated()
            ->assertJsonPath('data.name', 'Production app')
            ->assertJsonPath('data.description', 'Shared application contract.')
            ->assertJsonPath('data.required_files.0', 'associated-domains.json')
            ->assertJsonPath('data.platforms.0', Platform::Ios->value)
            ->assertJsonPath('data.requires_expo', true)
            ->assertJsonPath('data.applications_count', 0)
            ->assertJsonPath('data.hooks_count', 0);

        $blueprintId = $created->json('data.id');
        $app = MobileApp::factory()->create([
            'team_id' => $team->id,
            'blueprint_id' => $blueprintId,
            'metadata' => null,
            'tags' => [['blueprint-ready', '#10b981']],
        ]);

        $actions = [
            [
                'type' => BlueprintHookAction::RemoveTag->value,
                'tags' => [['blueprint-ready']],
            ],
            [
                'type' => BlueprintHookAction::AddTag->value,
                'tags' => [['blueprint-incomplete', '#F43F5E', 'heartBeat']],
            ],
        ];

        $hook = $this->postJson($this->api($team, "/blueprints/{$blueprintId}/hooks"), [
            'name' => 'Flag incomplete apps',
            'condition' => BlueprintHookCondition::BlueprintMismatch->value,
            'actions' => $actions,
        ])->assertCreated()
            ->assertJsonPath('data.condition', BlueprintHookCondition::BlueprintMismatch->value)
            ->assertJsonPath('data.actions.1.tags.0.1', '#f43f5e')
            ->assertJsonPath('data.actions.1.tags.0.2', 'heartbeat')
            ->assertJsonPath('data.enabled', true);

        $hookId = $hook->json('data.id');
        $this->assertSame([['blueprint-incomplete', '#f43f5e', 'heartbeat']], $app->fresh()->tags);

        $this->getJson($this->api($team, '/blueprints'))
            ->assertOk()
            ->assertJsonPath('data.0.id', $blueprintId)
            ->assertJsonPath('data.0.applications_count', 1)
            ->assertJsonPath('data.0.hooks_count', 1);

        $this->getJson($this->api($team, "/blueprints/{$blueprintId}"))
            ->assertOk()
            ->assertJsonPath('data.hooks.0.id', $hookId);

        $this->getJson($this->api($team, "/blueprints/{$blueprintId}/hooks"))
            ->assertOk()
            ->assertJsonPath('data.0.id', $hookId);

        $this->patchJson($this->api($team, "/blueprints/{$blueprintId}"), [
            'description' => null,
        ])->assertOk()
            ->assertJsonPath('data.name', 'Production app')
            ->assertJsonPath('data.description', null);

        $this->patchJson($this->api($team, "/blueprints/{$blueprintId}/hooks/{$hookId}"), [
            'name' => 'Flag incomplete applications',
            'enabled' => false,
        ])->assertOk()
            ->assertJsonPath('data.name', 'Flag incomplete applications')
            ->assertJsonPath('data.condition', BlueprintHookCondition::BlueprintMismatch->value)
            ->assertJsonPath('data.enabled', false);

        $this->postJson($this->api($team, "/blueprints/{$blueprintId}/evaluate"))
            ->assertOk()
            ->assertJsonPath('data.evaluated_applications', 1);

        $this->deleteJson($this->api($team, "/blueprints/{$blueprintId}/hooks/{$hookId}"))
            ->assertNoContent();
        $this->deleteJson($this->api($team, "/blueprints/{$blueprintId}"))
            ->assertNoContent();

        $this->assertSame($blueprintId, $app->fresh()->blueprint_id);
        $this->assertSoftDeleted('blueprints', ['id' => $blueprintId]);
        $this->assertSoftDeleted('blueprint_hooks', ['id' => $hookId]);

        $this->postJson($this->api($team, "/blueprints/trash/{$blueprintId}/restore"))
            ->assertOk()
            ->assertJsonPath('data.id', $blueprintId);
        $this->assertDatabaseHas('blueprints', ['id' => $blueprintId, 'deleted_at' => null]);
    }

    public function test_blueprint_api_uses_the_same_validation_contract_as_the_web_ui(): void
    {
        [$user, $team] = $this->teamOwner();
        $this->authenticate($user, $team, [ApiKeyScope::BlueprintsWrite]);

        $this->postJson($this->api($team, '/blueprints'), [
            'name' => 'Invalid blueprint',
            'required_files' => ['config/path.json'],
            'platforms' => ['windows'],
        ])->assertUnprocessable()
            ->assertJsonValidationErrors(['required_files.0', 'platforms.0']);

        $blueprint = Blueprint::factory()->create([
            'team_id' => $team->id,
            'required_files' => ['associated-domains.json'],
        ]);

        $this->postJson($this->api($team, "/blueprints/{$blueprint->id}/hooks"), [
            'name' => 'Missing undeclared file',
            'condition' => BlueprintHookCondition::FileMissing->value,
            'condition_config' => ['file_name' => 'other.json'],
            'actions' => [[
                'type' => BlueprintHookAction::AddTag->value,
                'tags' => [['invalid-tag', 'red', 'spin']],
            ]],
        ])->assertUnprocessable()
            ->assertJsonValidationErrors([
                'condition_config.file_name',
                'actions.0.tags.0.1',
                'actions.0.tags.0.2',
            ]);
    }

    public function test_blueprint_scopes_and_team_boundaries_are_enforced(): void
    {
        [$user, $team] = $this->teamOwner();
        $blueprint = Blueprint::factory()->create(['team_id' => $team->id]);
        $otherTeamBlueprint = Blueprint::factory()->create(['team_id' => $team->id]);
        $otherBlueprintHook = $otherTeamBlueprint->hooks()->create([
            'name' => 'Other blueprint hook',
            'condition' => BlueprintHookCondition::BlueprintMatches,
            'actions' => [[
                'type' => BlueprintHookAction::AddTag->value,
                'tags' => [['ready']],
            ]],
        ]);
        $foreignBlueprint = Blueprint::factory()->create();

        $this->authenticate($user, $team, [ApiKeyScope::BlueprintsRead]);
        $this->getJson($this->api($team, '/blueprints'))->assertOk();
        $this->getJson($this->api($team, "/blueprints/{$foreignBlueprint->id}"))->assertNotFound();
        $this->getJson($this->api($team, "/blueprints/{$blueprint->id}/hooks/{$otherBlueprintHook->id}"))->assertNotFound();
        $this->postJson($this->api($team, '/blueprints'), ['name' => 'Forbidden'])->assertForbidden();

        $this->authenticate($user, $team, [ApiKeyScope::BlueprintsWrite]);
        $this->patchJson($this->api($team, "/blueprints/{$blueprint->id}"), ['name' => 'Updated'])
            ->assertOk();
        $this->getJson($this->api($team, '/blueprints'))->assertForbidden();
    }

    /** @return array{User, Team} */
    private function teamOwner(): array
    {
        $user = User::factory()->create();
        $team = Team::factory()->create(['owner_id' => $user->id]);
        $team->users()->attach($user, ['role' => 'owner']);
        $user->update(['current_team_id' => $team->id]);

        return [$user, $team];
    }

    /** @param list<ApiKeyScope> $scopes */
    private function authenticate(User $user, Team $team, array $scopes): void
    {
        $token = $user->createTeamApiKey(
            $team,
            'Blueprint API',
            array_map(fn (ApiKeyScope $scope): string => $scope->value, $scopes),
        )->plainTextToken;

        $this->app['auth']->guard('sanctum')->forgetUser();
        $this->withToken($token);
    }

    private function api(Team $team, string $path): string
    {
        return "/api/v1/{$team->slug}{$path}";
    }
}

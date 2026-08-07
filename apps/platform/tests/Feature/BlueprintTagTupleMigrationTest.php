<?php

namespace Tests\Feature;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Tests\TestCase;

class BlueprintTagTupleMigrationTest extends TestCase
{
    use RefreshDatabase;

    public function test_legacy_scalar_hook_tag_is_migrated_to_the_tuple_array_shape(): void
    {
        Schema::table('blueprint_hooks', function (Blueprint $table) {
            $table->dropColumn('actions');
        });
        Schema::table('blueprint_hooks', function (Blueprint $table) {
            $table->string('action');
            $table->string('tag', 50)->nullable();
            $table->string('tag_color', 7)->nullable();
            $table->string('tag_animation')->nullable();
        });

        $teamId = Str::uuid()->toString();
        $blueprintId = Str::uuid()->toString();
        $hookId = Str::uuid()->toString();

        DB::table('users')->insert([
            'id' => $teamId,
            'name' => 'Migration Owner',
            'email' => 'migration-owner@example.test',
            'password' => 'unused',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        DB::table('teams')->insert([
            'id' => $teamId,
            'owner_id' => $teamId,
            'name' => 'Migration Team',
            'slug' => 'migration-team',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        DB::table('blueprints')->insert([
            'id' => $blueprintId,
            'team_id' => $teamId,
            'name' => 'Legacy blueprint',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        DB::table('blueprint_hooks')->insert([
            'id' => $hookId,
            'blueprint_id' => $blueprintId,
            'name' => 'Legacy hook',
            'condition' => 'platform_missing',
            'condition_config' => json_encode(['platform' => 'web'], JSON_THROW_ON_ERROR),
            'action' => 'add_tag',
            'tag' => 'no web',
            'tag_color' => '#f43f5e',
            'tag_animation' => 'flash',
            'enabled' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $migration = require database_path('migrations/2026_08_06_010000_migrate_blueprint_hook_tags_to_tuples.php');
        $migration->up();

        $this->assertTrue(Schema::hasColumn('blueprint_hooks', 'tags'));
        $this->assertSame(
            [['no web', '#f43f5e', 'flash']],
            json_decode(DB::table('blueprint_hooks')->where('id', $hookId)->value('tags'), true, flags: JSON_THROW_ON_ERROR),
        );

        $cleanup = require database_path('migrations/2026_08_06_020000_drop_legacy_blueprint_hook_tag_columns.php');
        $cleanup->up();

        $this->assertFalse(Schema::hasColumn('blueprint_hooks', 'tag'));
        $this->assertFalse(Schema::hasColumn('blueprint_hooks', 'tag_color'));
        $this->assertFalse(Schema::hasColumn('blueprint_hooks', 'tag_animation'));
        $this->assertSame(
            [['no web', '#f43f5e', 'flash']],
            json_decode(DB::table('blueprint_hooks')->where('id', $hookId)->value('tags'), true, flags: JSON_THROW_ON_ERROR),
        );

        $tupleOnlyHookId = Str::uuid()->toString();
        DB::table('blueprint_hooks')->insert([
            'id' => $tupleOnlyHookId,
            'blueprint_id' => $blueprintId,
            'name' => 'Tuple-only hook',
            'condition' => 'blueprint_matches',
            'condition_config' => null,
            'action' => 'remove_tag',
            'tags' => json_encode([['no web', '#f43f5e']], JSON_THROW_ON_ERROR),
            'enabled' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->assertDatabaseHas('blueprint_hooks', ['name' => 'Tuple-only hook']);

        $actionsMigration = require database_path('migrations/2026_08_06_030000_migrate_blueprint_hook_actions_to_array.php');
        $actionsMigration->up();

        $this->assertFalse(Schema::hasColumn('blueprint_hooks', 'action'));
        $this->assertFalse(Schema::hasColumn('blueprint_hooks', 'tags'));
        $this->assertSame(
            [[
                'type' => 'remove_tag',
                'tags' => [['no web', '#f43f5e']],
            ]],
            json_decode(DB::table('blueprint_hooks')->where('id', $tupleOnlyHookId)->value('actions'), true, flags: JSON_THROW_ON_ERROR),
        );

        DB::table('blueprint_hooks')->insert([
            'id' => Str::uuid()->toString(),
            'blueprint_id' => $blueprintId,
            'name' => 'Multiple actions hook',
            'condition' => 'blueprint_matches',
            'condition_config' => null,
            'actions' => json_encode([
                ['type' => 'remove_tag', 'tags' => [['incomplete']]],
                ['type' => 'add_tag', 'tags' => [['ready', '#10b981', 'pulse']]],
            ], JSON_THROW_ON_ERROR),
            'enabled' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->assertDatabaseHas('blueprint_hooks', ['name' => 'Multiple actions hook']);
    }
}

<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint as SchemaBlueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('blueprints', function (SchemaBlueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('team_id')->constrained()->cascadeOnDelete();
            $table->string('name');
            $table->text('description')->nullable();
            $table->json('json_schema')->nullable();
            $table->json('required_files')->nullable();
            $table->json('platforms')->nullable();
            $table->timestamps();

            $table->unique(['team_id', 'name']);
        });

        Schema::table('mobile_apps', function (SchemaBlueprint $table) {
            $table->foreignUuid('blueprint_id')->nullable()->after('team_id')
                ->constrained()->nullOnDelete();
        });

        Schema::create('blueprint_hooks', function (SchemaBlueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('blueprint_id')->constrained()->cascadeOnDelete();
            $table->string('name');
            $table->string('condition');
            $table->json('condition_config')->nullable();
            $table->json('actions');
            $table->boolean('enabled')->default(true);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('blueprint_hooks');

        Schema::table('mobile_apps', function (SchemaBlueprint $table) {
            $table->dropConstrainedForeignId('blueprint_id');
        });

        Schema::dropIfExists('blueprints');
    }
};

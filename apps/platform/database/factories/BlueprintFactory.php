<?php

namespace Database\Factories;

use App\Models\Blueprint;
use App\Models\Team;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<Blueprint> */
class BlueprintFactory extends Factory
{
    protected $model = Blueprint::class;

    public function definition(): array
    {
        return [
            'team_id' => Team::factory(),
            'name' => ucfirst(fake()->unique()->words(2, true)),
            'description' => fake()->sentence(),
            'json_schema' => null,
            'required_files' => [],
            'platforms' => [],
            'requires_expo' => null,
        ];
    }
}

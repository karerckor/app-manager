<?php

namespace Database\Factories;

use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<\App\Models\Team>
 */
class TeamFactory extends Factory
{
    public function definition(): array
    {
        return [
            'name' => fake()->company(),
            'owner_id' => User::factory(),
        ];
    }
}

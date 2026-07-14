<?php

namespace Database\Factories;

use App\Models\Team;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<\App\Models\MobileApp>
 */
class MobileAppFactory extends Factory
{
    public function definition(): array
    {
        $name = fake()->unique()->words(2, true);

        return [
            'team_id' => Team::factory(),
            'slug' => Str::slug($name).'-'.fake()->unique()->numberBetween(1, 999999),
            'name' => ucfirst($name),
            'description' => fake()->sentence(),
        ];
    }
}

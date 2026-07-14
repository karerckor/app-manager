<?php

namespace Database\Factories;

use App\Enums\ProfileType;
use App\Models\AppIdentifier;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<\App\Models\Profile>
 */
class ProfileFactory extends Factory
{
    public function definition(): array
    {
        return [
            'app_identifier_id' => AppIdentifier::factory(),
            'type' => ProfileType::AppStore,
            'name' => fake()->words(2, true),
        ];
    }
}

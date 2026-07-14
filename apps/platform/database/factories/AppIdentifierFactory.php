<?php

namespace Database\Factories;

use App\Enums\Platform;
use App\Models\AppIdentifier;
use App\Models\MobileApp;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<AppIdentifier>
 */
class AppIdentifierFactory extends Factory
{
    public function definition(): array
    {
        return [
            'mobile_app_id' => MobileApp::factory(),
            'platform' => Platform::Ios,
            'identifier' => 'com.example.'.fake()->unique()->word().fake()->unique()->numberBetween(1, 999999),
            'name' => fake()->words(2, true),
            'version' => '1.0.0',
            'version_code' => null,
        ];
    }
}

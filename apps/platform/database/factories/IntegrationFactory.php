<?php

namespace Database\Factories;

use App\Enums\IntegrationKind;
use App\Enums\IntegrationProvider;
use App\Enums\IntegrationStatus;
use App\Models\Integration;
use App\Models\Team;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Integration>
 */
class IntegrationFactory extends Factory
{
    public function definition(): array
    {
        return [
            'team_id' => Team::factory(),
            'kind' => IntegrationKind::CiCd,
            'provider' => IntegrationProvider::GitHub,
            'name' => 'GitHub '.fake()->unique()->company(),
            'status' => IntegrationStatus::Active,
            'external_id' => (string) fake()->unique()->numberBetween(1000, 9999999),
            'external_account_id' => (string) fake()->unique()->numberBetween(1000, 9999999),
            'external_account_name' => fake()->userName(),
            'settings' => ['repository_selection' => 'selected'],
        ];
    }
}

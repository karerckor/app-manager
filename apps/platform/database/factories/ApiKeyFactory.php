<?php

namespace Database\Factories;

use App\Models\ApiKey;
use App\Models\Team;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<ApiKey> */
class ApiKeyFactory extends Factory
{
    protected $model = ApiKey::class;

    public function definition(): array
    {
        return [
            'team_id' => Team::factory(),
            'tokenable_id' => User::factory(),
            'tokenable_type' => User::class,
            'name' => fake()->words(2, true),
            'token' => hash('sha256', fake()->unique()->uuid()),
            'abilities' => ['*'],
        ];
    }
}

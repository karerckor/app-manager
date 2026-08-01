<?php

namespace Database\Factories;

use App\Enums\CredentialType;
use App\Models\Credential;
use App\Models\Team;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Credential>
 */
class CredentialFactory extends Factory
{
    public function definition(): array
    {
        return [
            'team_id' => Team::factory(),
            'type' => CredentialType::IosCertificate,
            'name' => fake()->words(2, true),
            'payload' => null,
            'expires_at' => null,
        ];
    }
}

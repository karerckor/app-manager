<?php

namespace Database\Factories;

use App\Enums\CredentialType;
use App\Models\Credential;
use App\Models\EncryptionKey;
use App\Models\Team;
use App\Support\Encryption\EncryptionKeyManager;
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

    public function configure(): static
    {
        return $this->afterMaking(function () {
            if (EncryptionKey::count() === 0) {
                EncryptionKeyManager::createKey();
            }
        });
    }
}

<?php

namespace Database\Factories;

use App\Enums\CredentialStorageMode;
use App\Enums\CredentialType;
use App\Enums\VaultProvider;
use App\Models\Credential;
use App\Models\CredentialVaultReference;
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
            'storage_mode' => CredentialStorageMode::Local,
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

    public function external(?VaultProvider $provider = null, ?string $resource = null): static
    {
        return $this
            ->state(fn (): array => ['storage_mode' => CredentialStorageMode::External])
            ->afterCreating(function ($credential) use ($provider, $resource): void {
                CredentialVaultReference::factory()->create([
                    'credential_id' => $credential->id,
                    'team_id' => $credential->team_id,
                    ...($provider ? ['provider' => $provider] : []),
                    ...($resource ? ['resource' => $resource] : []),
                ]);
            });
    }
}

<?php

namespace Tests\Feature;

use App\Enums\CredentialStorageMode;
use App\Models\Team;
use App\Models\User;
use App\Support\Credentials\StorageModePolicy;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CredentialStorageModePolicyTest extends TestCase
{
    use RefreshDatabase;

    public function test_instance_ceiling_is_parsed_from_config(): void
    {
        config(['credentials.storage_modes' => 'local, external']);

        $this->assertSame(
            [CredentialStorageMode::Local, CredentialStorageMode::External],
            $this->policy()->instanceModes(),
        );
    }

    public function test_unparseable_config_falls_back_to_local(): void
    {
        config(['credentials.storage_modes' => 'nonsense,,']);

        $this->assertSame([CredentialStorageMode::Local], $this->policy()->instanceModes());
    }

    public function test_null_team_setting_inherits_the_ceiling(): void
    {
        config(['credentials.storage_modes' => 'local,external']);
        $team = Team::factory()->create(['allowed_credential_storage_modes' => null]);

        $this->assertSame(
            ['local', 'external'],
            $this->policy()->allowedValuesFor($team),
        );
    }

    public function test_team_narrows_the_instance_ceiling(): void
    {
        config(['credentials.storage_modes' => 'local,external']);
        $team = Team::factory()->create(['allowed_credential_storage_modes' => ['external']]);

        $this->assertSame(['external'], $this->policy()->allowedValuesFor($team));
        $this->assertFalse($this->policy()->permits($team, CredentialStorageMode::Local));
        $this->assertTrue($this->policy()->permits($team, CredentialStorageMode::External));
    }

    public function test_team_cannot_widen_beyond_the_ceiling(): void
    {
        config(['credentials.storage_modes' => 'local']);
        $team = Team::factory()->create([
            'allowed_credential_storage_modes' => ['local', 'external'],
        ]);

        $this->assertSame(['local'], $this->policy()->allowedValuesFor($team));
    }

    public function test_empty_intersection_falls_back_to_the_ceiling(): void
    {
        config(['credentials.storage_modes' => 'local']);
        $team = Team::factory()->create(['allowed_credential_storage_modes' => ['external']]);

        $this->assertSame(['local'], $this->policy()->allowedValuesFor($team));
        $this->assertSame(CredentialStorageMode::Local, $this->policy()->defaultFor($team));
    }

    public function test_owner_narrows_the_permitted_modes_from_settings(): void
    {
        config(['credentials.storage_modes' => 'local,external']);
        $owner = User::factory()->create();
        $team = Team::factory()->create(['owner_id' => $owner->id]);
        $team->addUser($owner, 'owner');
        $owner->update(['current_team_id' => $team->id]);

        $this->actingAs($owner->fresh())
            ->patch(route('accounts.team.update', $team), [
                'name' => $team->name,
                'check_credential_uniqueness_by_default' => true,
                'allowed_credential_storage_modes' => ['external'],
            ])
            ->assertRedirect();

        $this->assertSame(['external'], $team->fresh()->allowed_credential_storage_modes);
    }

    public function test_a_mode_outside_the_instance_ceiling_is_rejected(): void
    {
        config(['credentials.storage_modes' => 'local']);
        $owner = User::factory()->create();
        $team = Team::factory()->create(['owner_id' => $owner->id]);
        $team->addUser($owner, 'owner');
        $owner->update(['current_team_id' => $team->id]);

        $this->actingAs($owner->fresh())
            ->patch(route('accounts.team.update', $team), [
                'name' => $team->name,
                'allowed_credential_storage_modes' => ['external'],
            ])
            ->assertSessionHasErrors('allowed_credential_storage_modes.0');
    }

    private function policy(): StorageModePolicy
    {
        return new StorageModePolicy;
    }
}

<?php

namespace Tests\Feature;

use App\Models\AppIdentifier;
use App\Models\Credential;
use App\Models\MobileApp;
use App\Models\Profile;
use App\Models\Team;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class ModelUuidTest extends TestCase
{
    use RefreshDatabase;

    public function test_application_models_use_uuid_primary_and_foreign_keys(): void
    {
        $user = User::factory()->create();
        $team = Team::factory()->create(['owner_id' => $user->id]);
        $user->teams()->attach($team, ['role' => 'owner']);
        $user->update(['current_team_id' => $team->id]);

        $mobileApp = MobileApp::factory()->create(['team_id' => $team->id]);
        $appIdentifier = AppIdentifier::factory()->create(['mobile_app_id' => $mobileApp->id]);
        $profile = Profile::factory()->create(['app_identifier_id' => $appIdentifier->id]);
        $credential = Credential::factory()->create(['team_id' => $team->id]);
        $profile->credentials()->attach($credential);

        $models = [$user, $team, $mobileApp, $appIdentifier, $profile, $credential];

        foreach ($models as $model) {
            $this->assertTrue(Str::isUuid($model->getKey()));
            $this->assertSame('string', $model->getKeyType());
            $this->assertFalse($model->getIncrementing());
        }

        $foreignKeys = [
            $team->owner_id,
            $user->fresh()->current_team_id,
            $mobileApp->team_id,
            $appIdentifier->mobile_app_id,
            $profile->app_identifier_id,
            $credential->team_id,
        ];

        foreach ($foreignKeys as $foreignKey) {
            $this->assertTrue(Str::isUuid($foreignKey));
        }

        $this->assertDatabaseHas('team_user', [
            'team_id' => $team->id,
            'user_id' => $user->id,
        ]);
        $this->assertDatabaseHas('credentialables', [
            'credential_id' => $credential->id,
            'credentialable_id' => $profile->id,
        ]);
    }
}

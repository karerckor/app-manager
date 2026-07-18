<?php

namespace Database\Factories;

use App\Enums\BuildStatus;
use App\Enums\ProfileType;
use App\Models\AppIdentifier;
use App\Models\BuildRecord;
use App\Models\MobileApp;
use App\Models\Team;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<BuildRecord>
 */
class BuildRecordFactory extends Factory
{
    public function definition(): array
    {
        $team = Team::factory();
        $mobileApp = MobileApp::factory()->for($team);

        return [
            'team_id' => $team,
            'mobile_app_id' => $mobileApp,
            'app_identifier_id' => AppIdentifier::factory()->for($mobileApp),
            'requested_by_user_id' => User::factory(),
            'request_uuid' => (string) Str::uuid(),
            'profile_type' => ProfileType::Production,
            'status' => BuildStatus::Pending,
            'context_snapshot' => ['schema_version' => 1],
        ];
    }
}

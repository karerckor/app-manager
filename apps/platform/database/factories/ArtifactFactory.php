<?php

namespace Database\Factories;

use App\Models\Artifact;
use App\Models\BuildRecord;
use App\Models\Team;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Artifact>
 */
class ArtifactFactory extends Factory
{
    public function definition(): array
    {
        $build = BuildRecord::factory();

        return [
            'team_id' => Team::factory(),
            'build_record_id' => $build,
            'name' => fake()->word().'.apk',
            'metadata' => null,
        ];
    }
}

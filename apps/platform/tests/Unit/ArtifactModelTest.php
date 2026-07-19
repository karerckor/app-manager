<?php

namespace Tests\Unit;

use App\Models\Artifact;
use App\Models\BuildRecord;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ArtifactModelTest extends TestCase
{
    use RefreshDatabase;

    public function test_artifact_belongs_to_build_and_casts_metadata(): void
    {
        $build = BuildRecord::factory()->create();
        $artifact = Artifact::factory()->for($build)->create([
            'team_id' => $build->team_id,
            'name' => 'app-release.apk',
            'metadata' => ['git_sha' => 'abc123', 'size_label' => '42MB'],
        ]);

        $this->assertSame($build->id, $artifact->buildRecord->id);
        $this->assertSame('abc123', $artifact->metadata['git_sha']);
        $this->assertTrue($build->artifacts->contains($artifact));
    }
}

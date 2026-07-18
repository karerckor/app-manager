<?php

namespace Database\Factories;

use App\Enums\BuildReportingMode;
use App\Enums\ProfileType;
use App\Models\CiCdPipeline;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<CiCdPipeline> */
class CiCdPipelineFactory extends Factory
{
    protected $model = CiCdPipeline::class;

    public function definition(): array
    {
        return [
            'name' => 'Release',
            'profile_type' => ProfileType::Production,
            'enabled' => true,
            'reporting_mode' => BuildReportingMode::Automatic,
            'repository_id' => '100',
            'repository_full_name' => 'octo/mobile',
            'workflow_id' => '200',
            'workflow_name' => 'Release',
            'workflow_path' => '.github/workflows/release.yml',
            'default_ref' => 'main',
            'input_schema' => [
                'channel' => ['required' => true, 'default' => 'internal', 'type' => 'choice', 'options' => ['internal', 'production'], 'description' => 'Release channel'],
            ],
            'input_defaults' => [],
        ];
    }
}

<?php

namespace Tests\Unit;

use App\Support\Storage\MediaStorageConfiguration;
use InvalidArgumentException;
use Tests\TestCase;

class MediaStorageConfigurationTest extends TestCase
{
    public function test_local_storage_uses_a_private_non_served_disk(): void
    {
        $configuration = app(MediaStorageConfiguration::class);
        $settings = $this->settings();
        $disk = $configuration->diskConfig($settings);

        $this->assertSame('media-local', $configuration->diskName($settings));
        $this->assertSame('local', $disk['driver']);
        $this->assertSame('private', $disk['visibility']);
        $this->assertFalse($disk['serve']);
        $this->assertTrue($disk['throw']);
    }

    public function test_aws_s3_supports_role_credentials_and_sse_kms(): void
    {
        $configuration = app(MediaStorageConfiguration::class);
        $settings = $this->settings([
            'driver' => 's3',
            's3' => [
                'access_key_id' => null,
                'secret_access_key' => null,
                'region' => 'eu-central-1',
                'bucket' => 'saas-private-media',
                'server_side_encryption' => 'aws:kms',
                'kms_key_id' => 'arn:aws:kms:eu-central-1:123456789012:key/example',
            ],
        ]);
        $disk = $configuration->diskConfig($settings);

        $this->assertSame('media-s3', $configuration->diskName($settings));
        $this->assertNull($disk['key']);
        $this->assertNull($disk['secret']);
        $this->assertNull($disk['endpoint']);
        $this->assertFalse($disk['use_path_style_endpoint']);
        $this->assertSame('private', $disk['visibility']);
        $this->assertSame([
            'ACL' => 'bucket-owner-full-control',
            'ServerSideEncryption' => 'aws:kms',
            'SSEKMSKeyId' => 'arn:aws:kms:eu-central-1:123456789012:key/example',
        ], $disk['options']);
    }

    public function test_minio_uses_a_custom_endpoint_path_style_and_prefix(): void
    {
        $configuration = app(MediaStorageConfiguration::class);
        $settings = $this->settings([
            'driver' => 's3',
            's3' => [
                'access_key_id' => 'minio',
                'secret_access_key' => 'minio-secret',
                'region' => 'us-east-1',
                'bucket' => 'app-manager',
                'endpoint' => 'http://minio:9000',
                'prefix' => 'private/media',
                'use_path_style_endpoint' => true,
                'object_acl' => 'private',
            ],
        ]);
        $disk = $configuration->diskConfig($settings);

        $this->assertSame('http://minio:9000', $disk['endpoint']);
        $this->assertSame('private/media', $disk['root']);
        $this->assertTrue($disk['use_path_style_endpoint']);
        $this->assertSame('private', $disk['options']['ACL']);
    }

    public function test_long_lived_s3_credentials_must_be_configured_as_a_pair(): void
    {
        $configuration = app(MediaStorageConfiguration::class);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('access key ID and secret access key');

        $configuration->diskConfig($this->settings([
            'driver' => 's3',
            's3' => [
                'access_key_id' => 'incomplete',
                'secret_access_key' => null,
                'region' => 'us-east-1',
                'bucket' => 'app-manager',
            ],
        ]));
    }

    /**
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function settings(array $overrides = []): array
    {
        return array_replace_recursive([
            'driver' => 'local',
            'disks' => [
                'local' => 'media-local',
                's3' => 'media-s3',
            ],
            'local' => [
                'root' => storage_path('framework/testing/disks/media'),
            ],
            's3' => [
                'access_key_id' => null,
                'secret_access_key' => null,
                'session_token' => null,
                'region' => 'us-east-1',
                'bucket' => null,
                'endpoint' => null,
                'url' => null,
                'prefix' => null,
                'use_path_style_endpoint' => false,
                'object_acl' => 'bucket-owner-full-control',
                'server_side_encryption' => null,
                'kms_key_id' => null,
            ],
        ], $overrides);
    }
}

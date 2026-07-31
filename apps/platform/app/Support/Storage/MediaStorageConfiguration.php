<?php

namespace App\Support\Storage;

use Illuminate\Support\Facades\Storage;
use InvalidArgumentException;

class MediaStorageConfiguration
{
    /** @param array<string, mixed> $overrides */
    public function apply(array $overrides = []): void
    {
        $settings = $this->resolve($overrides);
        $diskName = $this->diskName($settings);
        $diskConfig = $this->diskConfig($settings);
        $previousDiskName = config('media-library.disk_name');
        $previousDiskConfig = config("filesystems.disks.{$diskName}");

        config([
            'media-storage' => $settings,
            'media-library.disk_name' => $diskName,
            "filesystems.disks.{$diskName}" => $diskConfig,
        ]);

        if ($previousDiskName !== $diskName || $previousDiskConfig !== $diskConfig) {
            Storage::forgetDisk($diskName);
        }
    }

    /**
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    public function resolve(array $overrides = []): array
    {
        return array_replace_recursive((array) config('media-storage', []), $overrides);
    }

    /** @param array<string, mixed> $settings */
    public function diskName(array $settings): string
    {
        $driver = $this->driver($settings);
        $diskName = data_get($settings, "disks.{$driver}");

        if (! is_string($diskName) || $diskName === '') {
            throw new InvalidArgumentException("No media disk is configured for the [{$driver}] driver.");
        }

        return $diskName;
    }

    /**
     * @param  array<string, mixed>  $settings
     * @return array<string, mixed>
     */
    public function diskConfig(array $settings): array
    {
        return match ($this->driver($settings)) {
            'local' => $this->localDiskConfig($settings),
            's3' => $this->s3DiskConfig($settings),
        };
    }

    /** @param array<string, mixed> $settings */
    private function driver(array $settings): string
    {
        $driver = data_get($settings, 'driver', 'local');

        if (! in_array($driver, ['local', 's3'], true)) {
            throw new InvalidArgumentException('MEDIA_STORAGE_DRIVER must be either [local] or [s3].');
        }

        return $driver;
    }

    /**
     * @param  array<string, mixed>  $settings
     * @return array<string, mixed>
     */
    private function localDiskConfig(array $settings): array
    {
        $root = data_get($settings, 'local.root');

        if (! is_string($root) || $root === '') {
            throw new InvalidArgumentException('MEDIA_LOCAL_ROOT must be configured for local media storage.');
        }

        return [
            'driver' => 'local',
            'root' => $root,
            'serve' => false,
            'visibility' => 'private',
            'throw' => true,
            'report' => false,
        ];
    }

    /**
     * @param  array<string, mixed>  $settings
     * @return array<string, mixed>
     */
    private function s3DiskConfig(array $settings): array
    {
        $s3 = (array) data_get($settings, 's3', []);
        $bucket = $this->nullableString($s3['bucket'] ?? null);
        $region = $this->nullableString($s3['region'] ?? null);
        $key = $this->nullableString($s3['access_key_id'] ?? null);
        $secret = $this->nullableString($s3['secret_access_key'] ?? null);
        $token = $this->nullableString($s3['session_token'] ?? null);
        $encryption = $this->nullableString($s3['server_side_encryption'] ?? null);
        $kmsKeyId = $this->nullableString($s3['kms_key_id'] ?? null);
        $objectAcl = $this->nullableString($s3['object_acl'] ?? null) ?? 'bucket-owner-full-control';

        if ($bucket === null || $region === null) {
            throw new InvalidArgumentException('MEDIA_S3_BUCKET and MEDIA_S3_REGION are required for S3 media storage.');
        }

        if (($key === null) !== ($secret === null)) {
            throw new InvalidArgumentException('The S3 access key ID and secret access key must be configured together.');
        }

        if ($token !== null && $key === null) {
            throw new InvalidArgumentException('An S3 session token requires an access key ID and secret access key.');
        }

        if (! in_array($encryption, [null, 'AES256', 'aws:kms'], true)) {
            throw new InvalidArgumentException('MEDIA_S3_SERVER_SIDE_ENCRYPTION must be [AES256] or [aws:kms].');
        }

        if ($encryption === 'aws:kms' && $kmsKeyId === null) {
            throw new InvalidArgumentException('MEDIA_S3_KMS_KEY_ID is required when SSE-KMS is enabled.');
        }

        if (! in_array($objectAcl, ['private', 'bucket-owner-full-control'], true)) {
            throw new InvalidArgumentException('MEDIA_S3_OBJECT_ACL must be [private] or [bucket-owner-full-control].');
        }

        $options = ['ACL' => $objectAcl];

        if ($encryption !== null) {
            $options['ServerSideEncryption'] = $encryption;
        }

        if ($kmsKeyId !== null) {
            $options['SSEKMSKeyId'] = $kmsKeyId;
        }

        return [
            'driver' => 's3',
            'key' => $key,
            'secret' => $secret,
            'token' => $token,
            'region' => $region,
            'bucket' => $bucket,
            'url' => $this->nullableString($s3['url'] ?? null),
            'endpoint' => $this->nullableString($s3['endpoint'] ?? null),
            'root' => $this->nullableString($s3['prefix'] ?? null),
            'use_path_style_endpoint' => (bool) ($s3['use_path_style_endpoint'] ?? false),
            'visibility' => 'private',
            'throw' => true,
            'report' => false,
            'options' => $options,
        ];
    }

    private function nullableString(mixed $value): ?string
    {
        if (! is_string($value)) {
            return null;
        }

        $value = trim($value);

        return $value === '' ? null : $value;
    }
}

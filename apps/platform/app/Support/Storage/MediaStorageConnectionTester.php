<?php

namespace App\Support\Storage;

use Illuminate\Support\Facades\Storage;
use RuntimeException;
use Throwable;

class MediaStorageConnectionTester
{
    public function __construct(private readonly MediaStorageConfiguration $configuration) {}

    /** @param array<string, mixed> $settings */
    public function assertAvailable(array $settings): void
    {
        $path = '.app-manager-storage-check/'.bin2hex(random_bytes(16));
        $contents = random_bytes(32);
        $written = false;

        try {
            $disk = Storage::build($this->configuration->diskConfig($settings));
            $disk->put($path, $contents);
            $written = true;

            if ($disk->get($path) !== $contents) {
                throw new RuntimeException('The storage verification object could not be read back correctly.');
            }

            $disk->delete($path);

            if ($disk->exists($path)) {
                throw new RuntimeException('The storage verification object could not be deleted.');
            }

            $written = false;
        } catch (Throwable $exception) {
            throw new RuntimeException(
                'App Manager could not write, read, and delete a private verification object. Check the storage endpoint, bucket, credentials, and permissions.',
                previous: $exception,
            );
        } finally {
            if ($written) {
                try {
                    $disk->delete($path);
                } catch (Throwable) {
                    // A failed cleanup should not hide a successful read/write check.
                }
            }
        }
    }
}

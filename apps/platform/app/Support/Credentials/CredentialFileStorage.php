<?php

namespace App\Support\Credentials;

use App\Models\Credential;
use App\Support\Encryption\EncryptionKeyManager;
use App\Support\Encryption\EnvelopeEncryptor;
use Closure;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Storage;
use RuntimeException;
use Spatie\MediaLibrary\MediaCollections\Models\Media;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Throwable;

final class CredentialFileStorage
{
    private const ENCRYPTED_PREFIX_V1 = "APP_MANAGER_CREDENTIAL_FILE:v1\n";

    private const ENCRYPTED_PREFIX_V2 = 'APP_MANAGER_CREDENTIAL_FILE:v2:';

    private const ENCRYPTED_PROPERTY = 'credential_file_encrypted';

    private const ENCRYPTION_VERSION_PROPERTY = 'credential_file_encryption_version';

    private const ENCRYPTION_KEY_ID_PROPERTY = 'credential_file_encryption_key_id';

    public static function storeUploaded(Credential $credential, UploadedFile $file): Media
    {
        $path = $file->getRealPath();
        $contents = $path === false ? false : file_get_contents($path);

        if (! is_string($contents)) {
            throw new RuntimeException('Unable to read the uploaded credential file.');
        }

        return self::storeContents(
            $credential,
            $contents,
            $file->getClientOriginalName(),
            $file->getMimeType() ?: $file->getClientMimeType(),
        );
    }

    public static function storeContents(
        Credential $credential,
        string $contents,
        string $fileName,
        ?string $mimeType = null,
    ): Media {
        $activeKey = EncryptionKeyManager::activeKey();
        $dek = EncryptionKeyManager::decryptDEK($activeKey);

        $encrypted = self::ENCRYPTED_PREFIX_V2.$activeKey->id."\n".
            EnvelopeEncryptor::encrypt($contents, $dek, $activeKey->id);

        $media = $credential->addMediaFromString($encrypted)
            ->usingFileName($fileName)
            ->withCustomProperties([
                self::ENCRYPTED_PROPERTY => true,
                self::ENCRYPTION_VERSION_PROPERTY => 2,
                self::ENCRYPTION_KEY_ID_PROPERTY => $activeKey->id,
            ])
            ->toMediaCollection('file');

        $media->forceFill([
            'mime_type' => $mimeType ?: 'application/octet-stream',
            'size' => strlen($contents),
        ])->saveQuietly();

        return $media;
    }

    public static function contents(Media $media): string
    {
        $contents = Storage::disk($media->disk)->get($media->getPathRelativeToRoot());

        if (str_starts_with($contents, self::ENCRYPTED_PREFIX_V2)) {
            return self::decryptV2($contents);
        }

        if (str_starts_with($contents, self::ENCRYPTED_PREFIX_V1)) {
            return self::decryptV1(substr($contents, strlen(self::ENCRYPTED_PREFIX_V1)));
        }

        if ($media->getCustomProperty(self::ENCRYPTED_PROPERTY, false)) {
            return self::decryptV1($contents);
        }

        return $contents;
    }

    public static function encryptExisting(Media $media): bool
    {
        if ($media->getCustomProperty(self::ENCRYPTED_PROPERTY, false)) {
            return false;
        }

        $disk = Storage::disk($media->disk);
        $path = $media->getPathRelativeToRoot();
        $contents = $disk->get($path);

        if (! str_starts_with($contents, self::ENCRYPTED_PREFIX_V1)
            && ! str_starts_with($contents, self::ENCRYPTED_PREFIX_V2)) {
            $activeKey = EncryptionKeyManager::activeKey();
            $dek = EncryptionKeyManager::decryptDEK($activeKey);

            $encrypted = self::ENCRYPTED_PREFIX_V2.$activeKey->id."\n".
                EnvelopeEncryptor::encrypt($contents, $dek, $activeKey->id);

            $disk->put($path, $encrypted, ['visibility' => 'private']);
        }
        $media->setCustomProperty(self::ENCRYPTED_PROPERTY, true);
        $media->setCustomProperty(self::ENCRYPTION_VERSION_PROPERTY, 2);
        $media->saveQuietly();

        return true;
    }

    public static function download(Media $media): StreamedResponse
    {
        return response()->streamDownload(
            static function () use ($media): void {
                echo self::contents($media);
            },
            $media->file_name,
            [
                'Cache-Control' => 'no-store, private',
                'Content-Length' => (string) $media->size,
                'Content-Type' => $media->mime_type ?: 'application/octet-stream',
                'Pragma' => 'no-cache',
            ],
        );
    }

    public static function withTemporaryPlaintext(Media $media, Closure $callback): mixed
    {
        $directory = storage_path('framework/cache');

        if (! is_dir($directory) && ! mkdir($directory, 0700, true) && ! is_dir($directory)) {
            throw new RuntimeException('Unable to create the credential temporary directory.');
        }

        $path = tempnam($directory, 'credential-');

        if ($path === false) {
            throw new RuntimeException('Unable to create a temporary credential file.');
        }

        try {
            chmod($path, 0600);
            if (file_put_contents($path, self::contents($media), LOCK_EX) === false) {
                throw new RuntimeException('Unable to write a temporary credential file.');
            }

            return $callback($path);
        } finally {
            if (is_file($path) && ! unlink($path)) {
                report(new RuntimeException('Unable to remove a temporary credential file.'));
            }
        }
    }

    private static function decryptV2(string $contents): string
    {
        $newlinePos = strpos($contents, "\n");
        $jsonPayload = $newlinePos !== false ? substr($contents, $newlinePos + 1) : '';

        $keyId = EnvelopeEncryptor::extractKeyId($jsonPayload);
        $encryptionKey = EncryptionKeyManager::findKey($keyId);
        $dek = EncryptionKeyManager::decryptDEK($encryptionKey);

        return EnvelopeEncryptor::decrypt($jsonPayload, $dek);
    }

    private static function decryptV1(string $contents): string
    {
        try {
            return Crypt::decryptString($contents);
        } catch (Throwable $exception) {
            throw new RuntimeException('Unable to decrypt the stored credential file.', previous: $exception);
        }
    }
}

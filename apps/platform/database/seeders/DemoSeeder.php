<?php

namespace Database\Seeders;

use App\Enums\CredentialType;
use App\Enums\Platform;
use App\Enums\ProfileType;
use App\Models\Credential;
use App\Models\MobileApp;
use App\Models\User;
use Illuminate\Database\Seeder;

/**
 * Seeds a ready-to-use demo account for local development:
 * demo@example.com / Demo123! with the demo-api-key API key, an Example App,
 * Android and iOS app identifiers, release build profiles, and signing
 * credentials for both platforms.
 *
 * Idempotent — safe to run repeatedly (php artisan db:seed).
 */
class DemoSeeder extends Seeder
{
    public const API_KEY = 'demo-api-key';

    private const KEYSTORE_ALIAS = 'production';

    private const KEYSTORE_PASSWORD = 'DemoKeystore123!';

    private const IOS_BUNDLE_IDENTIFIER = 'com.example.app';

    private const IOS_CERTIFICATE_PASSWORD = 'DemoCertificate123!';

    private const IOS_TEAM_ID = 'DEMO123456';

    public function run(): void
    {
        // Demo user. The User model's 'hashed' cast hashes the password on set.
        $user = User::firstOrCreate(
            ['email' => 'demo@example.com'],
            ['name' => 'Demo User', 'password' => 'Demo123!'],
        );

        // Personal team — mirrors registration (App\Actions\Fortify\CreateNewUser):
        // user is the owner, pivot role 'owner', and it becomes the current team.
        $team = $user->currentTeam;

        if ($team === null) {
            $team = $user->teams()->create(
                ['name' => "Demo User's Team", 'owner_id' => $user->id],
                ['role' => 'owner'],
            );

            $user->update(['current_team_id' => $team->id]);
        }

        // Sanctum accepts tokens without the usual "id|" prefix and hashes
        // them before lookup. This keeps the demo key stable across seed runs
        // while storing only its SHA-256 hash in the database.
        $user->tokens()->updateOrCreate(
            ['name' => 'Demo API Key'],
            ['token' => hash('sha256', self::API_KEY), 'abilities' => ['*']],
        );

        // Example App.
        $app = MobileApp::firstOrCreate(
            ['team_id' => $team->id, 'slug' => 'example-app'],
            ['name' => 'Example App', 'description' => 'Demo application for local development.'],
        );

        // Android app identifier: com.example.app.
        $identifier = $app->appIdentifiers()->firstOrCreate(
            ['platform' => Platform::Android, 'identifier' => 'com.example.app'],
            ['name' => 'Example App (Android)'],
        );

        // Production build profile on that identifier.
        $profile = $identifier->profiles()->firstOrCreate(
            ['type' => ProfileType::Production],
            ['name' => 'Production'],
        );

        // Production keystore credential (team-owned). Secrets live in the
        // encrypted payload; the keystore file lives in the media 'file' collection.
        $keystore = Credential::updateOrCreate(
            ['team_id' => $team->id, 'type' => CredentialType::AndroidKeystore, 'name' => 'Production Keystore'],
            ['payload' => [
                'store_password' => self::KEYSTORE_PASSWORD,
                'key_alias' => self::KEYSTORE_ALIAS,
                // PKCS#12 uses one password for both the store and private key.
                'key_password' => self::KEYSTORE_PASSWORD,
            ]],
        );

        // Assign signing material to the production build target. Detaching the
        // old direct relation also upgrades databases seeded before build targets.
        $identifier->credentials()->detach($keystore->id);
        $profile->credentials()->syncWithoutDetaching([$keystore->id]);

        // Replace the old placeholder once with a real PKCS#12 keystore. The
        // friendly name is the Android key alias stored in the payload above.
        $media = $keystore->getFirstMedia('file');

        if (! $media || ! $this->isValidDemoKeystore($media->getPath())) {
            $keystore->addMediaFromString($this->makeDemoKeystore())
                ->usingFileName('production.keystore')
                ->toMediaCollection('file');
        }

        // iOS App Store target and its two required signing credentials.
        $iosIdentifier = $app->appIdentifiers()->firstOrCreate(
            ['platform' => Platform::Ios, 'identifier' => self::IOS_BUNDLE_IDENTIFIER],
            ['name' => 'Example App (iOS)'],
        );
        $iosBuildProfile = $iosIdentifier->profiles()->firstOrCreate(
            ['type' => ProfileType::AppStore],
            ['name' => 'App Store'],
        );
        $iosCertificate = Credential::updateOrCreate(
            ['team_id' => $team->id, 'type' => CredentialType::IosCertificate, 'name' => 'App Store Certificate'],
            ['payload' => ['password' => self::IOS_CERTIFICATE_PASSWORD]],
        );
        $provisioningProfile = Credential::updateOrCreate(
            ['team_id' => $team->id, 'type' => CredentialType::ProvisioningProfile, 'name' => 'App Store Provisioning Profile'],
            ['payload' => null],
        );

        $iosIdentifier->credentials()->detach([$iosCertificate->id, $provisioningProfile->id]);
        $iosBuildProfile->credentials()->syncWithoutDetaching([
            $iosCertificate->id,
            $provisioningProfile->id,
        ]);

        $certificateMedia = $iosCertificate->getFirstMedia('file');
        $profileMedia = $provisioningProfile->getFirstMedia('file');

        if (
            ! $certificateMedia
            || ! $this->isValidIosCertificate($certificateMedia->getPath())
            || ! $profileMedia
            || ! $this->isValidDemoProvisioningProfile($profileMedia->getPath())
        ) {
            $materials = $this->makeIosSigningMaterials();
            $iosCertificate->addMediaFromString($materials['certificate'])
                ->usingFileName('distribution.p12')
                ->toMediaCollection('file');
            $provisioningProfile->addMediaFromString($materials['provisioning_profile'])
                ->usingFileName('appstore.mobileprovision')
                ->toMediaCollection('file');
        }

        if (! $app->hasMedia('app_icon')) {
            $app->addMediaFromString($this->makePng(1024, '#3b82f6'))
                ->usingFileName('icon.png')
                ->toMediaCollection('app_icon');
        }

        if (! $app->hasMedia('splash_icon')) {
            $app->addMediaFromString($this->makePng(2048, '#0f172a'))
                ->usingFileName('splash.png')
                ->toMediaCollection('splash_icon');
        }
    }

    private function makePng(int $size, string $hex): string
    {
        $image = imagecreatetruecolor($size, $size);

        if ($image === false) {
            throw new \RuntimeException('Unable to create the demo image.');
        }

        [$r, $g, $b] = sscanf($hex, '#%02x%02x%02x');
        imagefill($image, 0, 0, imagecolorallocate($image, $r, $g, $b));

        ob_start();
        imagepng($image);
        $contents = (string) ob_get_clean();
        imagedestroy($image);

        return $contents;
    }

    private function isValidDemoKeystore(string $path): bool
    {
        $contents = @file_get_contents($path);
        $certificates = [];

        return is_string($contents)
            && @openssl_pkcs12_read($contents, $certificates, self::KEYSTORE_PASSWORD)
            && isset($certificates['pkey'], $certificates['cert']);
    }

    private function makeDemoKeystore(): string
    {
        $key = openssl_pkey_new([
            'private_key_bits' => 2048,
            'private_key_type' => OPENSSL_KEYTYPE_RSA,
        ]);

        if ($key === false) {
            throw new \RuntimeException('Unable to generate the demo keystore private key.');
        }

        $subject = [
            'commonName' => 'App Manager Demo',
            'organizationName' => 'App Manager',
            'organizationalUnitName' => 'Development',
            'countryName' => 'ES',
        ];
        $request = openssl_csr_new($subject, $key, ['digest_alg' => 'sha256']);

        if ($request === false) {
            throw new \RuntimeException('Unable to generate the demo keystore certificate request.');
        }

        $certificate = openssl_csr_sign($request, null, $key, 3650, ['digest_alg' => 'sha256']);

        if ($certificate === false) {
            throw new \RuntimeException('Unable to sign the demo keystore certificate.');
        }

        $exported = openssl_pkcs12_export(
            $certificate,
            $contents,
            $key,
            self::KEYSTORE_PASSWORD,
            ['friendly_name' => self::KEYSTORE_ALIAS],
        );

        if (! $exported) {
            throw new \RuntimeException('Unable to export the demo PKCS#12 keystore.');
        }

        return $contents;
    }

    private function isValidIosCertificate(string $path): bool
    {
        $contents = @file_get_contents($path);
        $certificates = [];

        return is_string($contents)
            && @openssl_pkcs12_read($contents, $certificates, self::IOS_CERTIFICATE_PASSWORD)
            && isset($certificates['pkey'], $certificates['cert']);
    }

    private function isValidDemoProvisioningProfile(string $path): bool
    {
        $decodedPath = tempnam(sys_get_temp_dir(), 'app-manager-profile-');

        if ($decodedPath === false) {
            return false;
        }

        try {
            $verified = @openssl_cms_verify(
                $path,
                OPENSSL_CMS_NOVERIFY,
                null,
                [],
                null,
                $decodedPath,
                null,
                null,
                OPENSSL_ENCODING_DER,
            );
            $contents = $verified ? @file_get_contents($decodedPath) : false;

            return is_string($contents)
                && str_contains(
                    $contents,
                    '<string>'.self::IOS_TEAM_ID.'.'.self::IOS_BUNDLE_IDENTIFIER.'</string>',
                )
                && str_contains($contents, '<string>'.self::IOS_TEAM_ID.'</string>');
        } finally {
            @unlink($decodedPath);
        }
    }

    private function makeIosSigningMaterials(): array
    {
        $key = openssl_pkey_new([
            'private_key_bits' => 2048,
            'private_key_type' => OPENSSL_KEYTYPE_RSA,
        ]);

        if ($key === false) {
            throw new \RuntimeException('Unable to generate the demo iOS certificate private key.');
        }

        $subject = [
            'commonName' => 'Apple Distribution: App Manager Demo ('.self::IOS_TEAM_ID.')',
            'organizationName' => 'App Manager',
            'organizationalUnitName' => self::IOS_TEAM_ID,
            'countryName' => 'ES',
        ];
        $request = openssl_csr_new($subject, $key, ['digest_alg' => 'sha256']);

        if ($request === false) {
            throw new \RuntimeException('Unable to generate the demo iOS certificate request.');
        }

        $certificate = openssl_csr_sign($request, null, $key, 3650, ['digest_alg' => 'sha256']);

        if ($certificate === false) {
            throw new \RuntimeException('Unable to sign the demo iOS certificate.');
        }

        $exported = openssl_pkcs12_export(
            $certificate,
            $certificateContents,
            $key,
            self::IOS_CERTIFICATE_PASSWORD,
            ['friendly_name' => 'distribution'],
        );

        if (! $exported) {
            throw new \RuntimeException('Unable to export the demo iOS PKCS#12 certificate.');
        }

        if (! openssl_x509_export($certificate, $certificatePem)) {
            throw new \RuntimeException('Unable to export the demo iOS public certificate.');
        }

        $certificateDer = base64_decode(
            preg_replace('/-----[^-]+-----|\s+/', '', $certificatePem),
            true,
        );

        if ($certificateDer === false) {
            throw new \RuntimeException('Unable to encode the demo iOS public certificate.');
        }

        return [
            'certificate' => $certificateContents,
            'provisioning_profile' => $this->makeDemoProvisioningProfile(
                $certificate,
                $key,
                $certificateDer,
            ),
        ];
    }

    private function makeDemoProvisioningProfile(
        \OpenSSLCertificate $certificate,
        \OpenSSLAsymmetricKey $key,
        string $certificateDer,
    ): string {
        $createdAt = now()->utc();
        $expiresAt = $createdAt->copy()->addYears(10);
        $encodedCertificate = base64_encode($certificateDer);
        $bundleIdentifier = self::IOS_BUNDLE_IDENTIFIER;
        $teamId = self::IOS_TEAM_ID;
        $profile = <<<XML
<?xml version="1.0" encoding="UTF-8"?>
<!DOCTYPE plist PUBLIC "-//Apple//DTD PLIST 1.0//EN" "http://www.apple.com/DTDs/PropertyList-1.0.dtd">
<plist version="1.0">
<dict>
    <key>AppIDName</key>
    <string>Example App</string>
    <key>ApplicationIdentifierPrefix</key>
    <array><string>{$teamId}</string></array>
    <key>CreationDate</key>
    <date>{$createdAt->format('Y-m-d\TH:i:s\Z')}</date>
    <key>DeveloperCertificates</key>
    <array><data>{$encodedCertificate}</data></array>
    <key>Entitlements</key>
    <dict>
        <key>application-identifier</key>
        <string>{$teamId}.{$bundleIdentifier}</string>
        <key>com.apple.developer.team-identifier</key>
        <string>{$teamId}</string>
        <key>get-task-allow</key>
        <false/>
    </dict>
    <key>ExpirationDate</key>
    <date>{$expiresAt->format('Y-m-d\TH:i:s\Z')}</date>
    <key>Name</key>
    <string>Example App Demo App Store</string>
    <key>Platform</key>
    <array><string>iOS</string></array>
    <key>TeamIdentifier</key>
    <array><string>{$teamId}</string></array>
    <key>TeamName</key>
    <string>App Manager Demo</string>
    <key>TimeToLive</key>
    <integer>3650</integer>
    <key>UUID</key>
    <string>00000000-0000-4000-8000-000000000001</string>
    <key>Version</key>
    <integer>1</integer>
</dict>
</plist>
XML;
        $inputPath = tempnam(sys_get_temp_dir(), 'app-manager-profile-input-');
        $outputPath = tempnam(sys_get_temp_dir(), 'app-manager-profile-output-');

        if ($inputPath === false || $outputPath === false) {
            throw new \RuntimeException('Unable to create temporary files for the demo provisioning profile.');
        }

        try {
            if (file_put_contents($inputPath, $profile) === false) {
                throw new \RuntimeException('Unable to write the demo provisioning profile payload.');
            }

            $signed = openssl_cms_sign(
                $inputPath,
                $outputPath,
                $certificate,
                $key,
                [],
                OPENSSL_CMS_BINARY,
                OPENSSL_ENCODING_DER,
            );
            $contents = $signed ? file_get_contents($outputPath) : false;

            if (! is_string($contents) || $contents === '') {
                throw new \RuntimeException('Unable to sign the demo provisioning profile.');
            }

            return $contents;
        } finally {
            @unlink($inputPath);
            @unlink($outputPath);
        }
    }
}

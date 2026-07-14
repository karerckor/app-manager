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
 * demo@example.com / Demo123! with an Example App, an Android app
 * identifier, a production profile, and a production keystore credential.
 *
 * Idempotent — safe to run repeatedly (php artisan db:seed).
 */
class DemoSeeder extends Seeder
{
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
        $identifier->profiles()->firstOrCreate(
            ['type' => ProfileType::Production],
            ['name' => 'Production'],
        );

        // Production keystore credential (team-owned). Secrets live in the
        // encrypted payload; the keystore file lives in the media 'file' collection.
        $keystore = Credential::firstOrCreate(
            ['team_id' => $team->id, 'type' => CredentialType::AndroidKeystore, 'name' => 'Production Keystore'],
            ['payload' => [
                'store_password' => 'demo-store-pass',
                'key_alias' => 'production',
                'key_password' => 'demo-key-pass',
            ]],
        );

        // Share the keystore with the Android identifier (idempotent attach).
        $identifier->credentials()->syncWithoutDetaching([$keystore->id]);

        // Attach a placeholder keystore file once (single-file collection).
        if (! $keystore->hasMedia('file')) {
            $keystore->addMediaFromString('demo-android-keystore-placeholder')
                ->usingFileName('production.keystore')
                ->toMediaCollection('file');
        }
    }
}

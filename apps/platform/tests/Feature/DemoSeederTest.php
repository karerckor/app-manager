<?php

namespace Tests\Feature;

use App\Enums\CredentialType;
use App\Enums\Platform;
use App\Enums\ProfileType;
use App\Models\Credential;
use App\Models\MobileApp;
use App\Models\User;
use Database\Seeders\DemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class DemoSeederTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_seeds_the_demo_account_graph(): void
    {
        Storage::fake('public');

        $this->seed(DemoSeeder::class);

        $user = User::where('email', 'demo@example.com')->firstOrFail();
        $this->assertTrue(Hash::check('Demo123!', $user->password));

        // Personal team, owner role, current team set.
        $team = $user->currentTeam;
        $this->assertNotNull($team);
        $this->assertSame($user->id, $team->owner_id);
        $this->assertSame('owner', $user->teams()->firstOrFail()->pivot->role);

        // Example App with an Android identifier and production profile.
        $app = MobileApp::where('slug', 'example-app')->firstOrFail();
        $this->assertSame('Example App', $app->name);
        $this->assertSame($team->id, $app->team_id);

        $identifier = $app->appIdentifiers()->firstOrFail();
        $this->assertSame(Platform::Android, $identifier->platform);
        $this->assertSame('com.example.app', $identifier->identifier);
        $this->assertSame(ProfileType::Production, $identifier->profiles()->firstOrFail()->type);

        // Production keystore credential, attached to the identifier, with file + secrets.
        $keystore = Credential::where('name', 'Production Keystore')->firstOrFail();
        $this->assertSame(CredentialType::AndroidKeystore, $keystore->type);
        $this->assertSame($team->id, $keystore->team_id);
        $this->assertSame('production', $keystore->payload['key_alias']);
        $this->assertTrue($identifier->credentials()->whereKey($keystore->id)->exists());
        $this->assertTrue($keystore->hasMedia('file'));
    }

    public function test_it_is_idempotent(): void
    {
        Storage::fake('public');

        $this->seed(DemoSeeder::class);
        $this->seed(DemoSeeder::class);

        $this->assertSame(1, User::where('email', 'demo@example.com')->count());
        $this->assertSame(1, MobileApp::where('slug', 'example-app')->count());
        $this->assertSame(1, Credential::where('name', 'Production Keystore')->count());
        $this->assertDatabaseCount('credentialables', 1);
        $this->assertDatabaseCount('media', 1);
    }
}

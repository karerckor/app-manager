<?php

namespace Tests\Feature;

use App\Models\Installation;
use App\Models\Team;
use App\Models\User;
use App\Support\Storage\MediaStorageConnectionTester;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Inertia\Testing\AssertableInertia as Assert;
use Mockery\MockInterface;
use Tests\TestCase;

class SelfHostedInstallerTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'installer.enabled' => true,
            'billing.distribution' => 'self-hosted',
            'app.key' => 'base64:AAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAA=',
            'integrations.enabled' => false,
            'integrations.github.enabled' => false,
            'integrations.gitlab.enabled' => false,
            'integrations.webhooks.enabled' => false,
        ]);
    }

    public function test_uninstalled_self_hosted_instance_redirects_to_installer(): void
    {
        $this->get('/')->assertRedirect(route('installer.index'));
        $this->post('/login', [])->assertStatus(503);

        $this->get(route('installer.index'))
            ->assertSuccessful()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Installer/Index')
                ->has('requirements', 6)
                ->where('urls.install', route('installer.store'))
                ->where('defaults.storage_driver', 'local')
                ->where('urls.github_webhook', route('webhooks.github')));
    }

    public function test_installer_creates_first_owner_team_and_encrypted_configuration(): void
    {
        $response = $this->post(route('installer.store'), $this->payload([
            'gitlab' => [
                'enabled' => true,
                'client_id' => 'gitlab-client-id',
                'client_secret' => 'gitlab-super-secret',
                'api_url' => 'https://gitlab.example.com/api/v4',
                'web_url' => 'https://gitlab.example.com',
            ],
        ]));

        $user = User::query()->where('email', 'owner@example.com')->firstOrFail();
        $team = Team::query()->firstOrFail();
        $installation = Installation::query()->findOrFail('self-hosted');

        $response->assertRedirect(route('accounts.integrations.index', ['team' => $team]));
        $this->assertAuthenticatedAs($user);
        $this->assertSame($user->id, $team->owner_id);
        $this->assertSame($team->id, $user->current_team_id);
        $this->assertDatabaseHas('team_user', [
            'team_id' => $team->id,
            'user_id' => $user->id,
            'role' => 'owner',
        ]);
        $this->assertSame('Private App Manager', $installation->name);
        $this->assertSame('local', $installation->settings['storage']['driver']);
        $this->assertTrue($installation->settings['integrations']['gitlab']['enabled']);
        $this->assertSame('gitlab-super-secret', $installation->settings['integrations']['gitlab']['client_secret']);
        $this->assertTrue(config('integrations.enabled'));
        $this->assertTrue(config('integrations.gitlab.enabled'));
        $this->assertSame('https://gitlab.example.com/api/v4', config('integrations.gitlab.api_url'));

        $rawSettings = (string) $installation->getRawOriginal('settings');
        $this->assertStringNotContainsString('gitlab-super-secret', $rawSettings);
        $this->assertStringNotContainsString('gitlab-client-id', $rawSettings);
    }

    public function test_installer_configures_s3_compatible_private_storage_and_encrypts_its_credentials(): void
    {
        $this->mock(MediaStorageConnectionTester::class, function (MockInterface $mock): void {
            $mock->shouldReceive('assertAvailable')
                ->once()
                ->withArgs(fn (array $settings) => $settings['driver'] === 's3'
                    && $settings['s3']['endpoint'] === 'http://minio:9000'
                    && $settings['s3']['use_path_style_endpoint'] === true);
        });

        $this->post(route('installer.store'), $this->payload([
            'storage' => [
                'driver' => 's3',
                's3' => [
                    'access_key_id' => 'minio-user',
                    'secret_access_key' => 'minio-super-secret',
                    'region' => 'us-east-1',
                    'bucket' => 'app-manager',
                    'endpoint' => 'http://minio:9000',
                    'prefix' => 'production/media',
                    'use_path_style_endpoint' => true,
                    'object_acl' => 'private',
                    'server_side_encryption' => '',
                    'kms_key_id' => '',
                ],
            ],
        ]))->assertRedirect();

        $installation = Installation::query()->findOrFail('self-hosted');

        $this->assertSame('s3', $installation->settings['storage']['driver']);
        $this->assertSame('http://minio:9000', config('filesystems.disks.media-s3.endpoint'));
        $this->assertSame('production/media', config('filesystems.disks.media-s3.root'));
        $this->assertTrue(config('filesystems.disks.media-s3.use_path_style_endpoint'));
        $this->assertSame('private', config('filesystems.disks.media-s3.options.ACL'));
        $this->assertSame('media-s3', config('media-library.disk_name'));

        $rawSettings = (string) $installation->getRawOriginal('settings');
        $this->assertStringNotContainsString('minio-user', $rawSettings);
        $this->assertStringNotContainsString('minio-super-secret', $rawSettings);
    }

    public function test_s3_credentials_must_be_supplied_as_a_pair(): void
    {
        $this->post(route('installer.store'), $this->payload([
            'storage' => [
                'driver' => 's3',
                's3' => [
                    'access_key_id' => 'incomplete-key',
                    'secret_access_key' => '',
                    'region' => 'us-east-1',
                    'bucket' => 'app-manager',
                    'endpoint' => '',
                    'prefix' => '',
                    'use_path_style_endpoint' => false,
                    'object_acl' => 'bucket-owner-full-control',
                    'server_side_encryption' => '',
                    'kms_key_id' => '',
                ],
            ],
        ]))->assertSessionHasErrors('storage.s3.secret_access_key');

        $this->assertDatabaseCount('installations', 0);
    }

    public function test_s3_secrets_are_not_flashed_after_validation_failure(): void
    {
        $response = $this->post(route('installer.store'), $this->payload([
            'storage' => [
                'driver' => 's3',
                's3' => [
                    'access_key_id' => 'temporary-key',
                    'secret_access_key' => 'must-not-be-flashed',
                    'session_token' => 'must-not-be-flashed-either',
                    'region' => 'us-east-1',
                    'bucket' => '',
                    'endpoint' => '',
                    'prefix' => '',
                    'use_path_style_endpoint' => false,
                    'object_acl' => 'bucket-owner-full-control',
                    'server_side_encryption' => '',
                    'kms_key_id' => '',
                ],
            ],
        ]));

        $response->assertSessionHasErrors('storage.s3.bucket');
        $this->assertNull(session()->getOldInput('storage.s3.secret_access_key'));
        $this->assertNull(session()->getOldInput('storage.s3.session_token'));
    }

    public function test_provider_credentials_are_required_only_when_provider_is_enabled(): void
    {
        $payload = $this->payload();
        $payload['github']['enabled'] = true;

        $this->post(route('installer.store'), $payload)
            ->assertSessionHasErrors([
                'github.app_id',
                'github.client_id',
                'github.client_secret',
                'github.slug',
                'github.private_key',
                'github.webhook_secret',
            ]);

        $this->assertDatabaseCount('users', 0);
        $this->assertDatabaseCount('installations', 0);
    }

    public function test_installer_and_public_registration_are_locked_after_installation(): void
    {
        $this->post(route('installer.store'), $this->payload())->assertRedirect();

        $this->get(route('installer.index'))->assertRedirect('/');
        $this->post(route('installer.store'), $this->payload())->assertRedirect('/');
        $this->get('/register')->assertNotFound();
        $this->post('/register', [
            'name' => 'Second User',
            'email' => 'second@example.com',
            'password' => 'AnotherPassword123!',
            'password_confirmation' => 'AnotherPassword123!',
        ])->assertNotFound();
        $this->assertDatabaseCount('users', 1);

        Auth::logout();
        $this->get('/login')
            ->assertSuccessful()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Auth/Login')
                ->where('auth.registration_enabled', false));
    }

    public function test_existing_users_prevent_installer_takeover_on_legacy_instance(): void
    {
        User::factory()->create();

        $this->get(route('installer.index'))->assertRedirect('/');
        $this->get('/register')->assertNotFound();
    }

    /** @param array<string, mixed> $overrides */
    private function payload(array $overrides = []): array
    {
        return array_replace_recursive([
            'instance_name' => 'Private App Manager',
            'team_name' => 'Mobile Platform',
            'admin_name' => 'First Owner',
            'email' => 'owner@example.com',
            'password' => 'InstallerPassword123!',
            'password_confirmation' => 'InstallerPassword123!',
            'storage' => [
                'driver' => 'local',
                's3' => [
                    'access_key_id' => '',
                    'secret_access_key' => '',
                    'session_token' => '',
                    'region' => 'us-east-1',
                    'bucket' => '',
                    'endpoint' => '',
                    'prefix' => '',
                    'use_path_style_endpoint' => false,
                    'object_acl' => 'bucket-owner-full-control',
                    'server_side_encryption' => '',
                    'kms_key_id' => '',
                ],
            ],
            'github' => [
                'enabled' => false,
                'app_id' => '',
                'client_id' => '',
                'client_secret' => '',
                'slug' => '',
                'private_key' => '',
                'webhook_secret' => '',
                'api_url' => 'https://api.github.com',
                'web_url' => 'https://github.com',
            ],
            'gitlab' => [
                'enabled' => false,
                'client_id' => '',
                'client_secret' => '',
                'api_url' => 'https://gitlab.com/api/v4',
                'web_url' => 'https://gitlab.com',
            ],
            'webhooks' => [
                'enabled' => true,
                'allow_private_addresses' => false,
            ],
        ], $overrides);
    }
}

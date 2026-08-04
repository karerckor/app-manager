<?php

namespace Tests\Feature;

use App\Models\Team;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class BootstrapInstanceCommandTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Prior values of environment variables overridden by setEnv(), so
     * restoreEnv() can put them back rather than merely unsetting them.
     *
     * @var array<string, string|false>
     */
    private array $priorEnv = [];

    /**
     * Laravel's Env repository is built immutable: it refuses to overwrite
     * (or clear) a variable already defined in the real environment, which
     * phpunit.xml does for SELF_HOSTED_INSTALLER_ENABLED. Bypassing it via
     * putenv()/$_ENV/$_SERVER directly — the pattern already used by
     * CredentialDashboardOnlyProfileTest — is the only way to override such
     * a variable for a single test.
     */
    private function setEnv(string $name, string $value): void
    {
        $this->priorEnv[$name] = getenv($name);

        putenv("{$name}={$value}");
        $_ENV[$name] = $value;
        $_SERVER[$name] = $value;
    }

    private function restoreEnv(string $name): void
    {
        $prior = $this->priorEnv[$name] ?? false;

        if ($prior === false) {
            putenv($name);
            unset($_ENV[$name], $_SERVER[$name]);
        } else {
            putenv("{$name}={$prior}");
            $_ENV[$name] = $prior;
            $_SERVER[$name] = $prior;
        }
    }

    private function useAdminMode(): void
    {
        config([
            'installer.bootstrap' => 'admin',
            'installer.admin.name' => 'Ada Lovelace',
            'installer.admin.email' => 'ada@example.com',
            'installer.admin.password' => 'Sup3rSecret!',
            'installer.admin.team_name' => 'Analytical Engine',
        ]);
    }

    public function test_installer_mode_writes_nothing(): void
    {
        config(['installer.bootstrap' => 'installer']);

        $this->artisan('app:bootstrap')->assertExitCode(0);

        $this->assertDatabaseCount('users', 0);
        $this->assertDatabaseCount('teams', 0);
        $this->assertDatabaseCount('installations', 0);
    }

    public function test_admin_mode_provisions_the_first_owner(): void
    {
        $this->useAdminMode();

        $this->artisan('app:bootstrap')->assertExitCode(0);

        $user = User::query()->where('email', 'ada@example.com')->firstOrFail();
        $team = Team::query()->firstOrFail();

        $this->assertSame('Ada Lovelace', $user->name);
        $this->assertSame('Analytical Engine', $team->name);
        $this->assertSame($user->id, $team->owner_id);
        $this->assertSame($team->id, $user->current_team_id);
        $this->assertDatabaseHas('team_user', [
            'team_id' => $team->id,
            'user_id' => $user->id,
            'role' => 'owner',
        ]);
        $this->assertDatabaseCount('installations', 0);
    }

    public function test_admin_mode_is_idempotent(): void
    {
        $this->useAdminMode();

        $this->artisan('app:bootstrap')->assertExitCode(0);
        $this->artisan('app:bootstrap')->assertExitCode(0);

        $this->assertDatabaseCount('users', 1);
        $this->assertDatabaseCount('teams', 1);
    }

    public function test_admin_mode_fails_when_the_email_is_missing(): void
    {
        $this->useAdminMode();
        config(['installer.admin.email' => null]);

        $this->artisan('app:bootstrap')
            ->expectsOutputToContain('ADMIN_EMAIL')
            ->assertExitCode(1);

        $this->assertDatabaseCount('users', 0);
    }

    public function test_admin_mode_fails_when_the_password_is_missing(): void
    {
        $this->useAdminMode();
        config(['installer.admin.password' => null]);

        $this->artisan('app:bootstrap')
            ->expectsOutputToContain('ADMIN_PASSWORD')
            ->assertExitCode(1);

        $this->assertDatabaseCount('users', 0);
    }

    public function test_admin_mode_reports_an_unusable_password_instead_of_throwing(): void
    {
        $this->useAdminMode();
        config(['installer.admin.password' => 'short']);

        $this->artisan('app:bootstrap')
            ->expectsOutputToContain('ADMIN_PASSWORD')
            ->assertExitCode(1);

        $this->assertDatabaseCount('users', 0);
    }

    public function test_demo_mode_seeds_the_demo_account_once(): void
    {
        Storage::fake(config('media-library.disk_name'));
        config(['installer.bootstrap' => 'demo']);

        $this->artisan('app:bootstrap')->assertExitCode(0);
        $this->artisan('app:bootstrap')->assertExitCode(0);

        $this->assertDatabaseCount('users', 1);
        $this->assertDatabaseHas('users', ['email' => 'demo@example.com']);
    }

    public function test_an_unknown_mode_fails(): void
    {
        config(['installer.bootstrap' => 'nonsense']);

        $this->artisan('app:bootstrap')
            ->expectsOutputToContain('nonsense')
            ->assertExitCode(1);
    }

    public function test_the_mode_option_overrides_the_configured_mode(): void
    {
        config(['installer.bootstrap' => 'admin']);

        $this->artisan('app:bootstrap', ['--mode' => 'installer'])->assertExitCode(0);

        $this->assertDatabaseCount('users', 0);
    }

    public function test_the_web_installer_is_disabled_outside_installer_mode(): void
    {
        $this->setEnv('APP_MANAGER_BOOTSTRAP', 'admin');

        try {
            $config = require config_path('installer.php');
        } finally {
            $this->restoreEnv('APP_MANAGER_BOOTSTRAP');
        }

        $this->assertFalse($config['enabled']);
    }

    public function test_the_web_installer_stays_enabled_in_installer_mode(): void
    {
        $this->setEnv('APP_MANAGER_BOOTSTRAP', 'installer');
        $this->setEnv('SELF_HOSTED_INSTALLER_ENABLED', 'true');

        try {
            $config = require config_path('installer.php');
        } finally {
            $this->restoreEnv('APP_MANAGER_BOOTSTRAP');
            $this->restoreEnv('SELF_HOSTED_INSTALLER_ENABLED');
        }

        $this->assertTrue($config['enabled']);
    }
}

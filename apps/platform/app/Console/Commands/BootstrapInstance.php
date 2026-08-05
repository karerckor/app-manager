<?php

namespace App\Console\Commands;

use App\Actions\Installation\ProvisionFirstOwner;
use App\Models\User;
use Database\Seeders\DemoSeeder;
use Illuminate\Console\Command;
use Illuminate\Validation\ValidationException;

class BootstrapInstance extends Command
{
    protected $signature = 'app:bootstrap {--mode= : installer, admin or demo. Defaults to APP_MANAGER_BOOTSTRAP.}';

    protected $description = 'Apply the configured first-run bootstrap mode. Safe to run on every start.';

    private const MODES = ['installer', 'admin', 'demo'];

    public function handle(ProvisionFirstOwner $provisionFirstOwner): int
    {
        $mode = (string) ($this->option('mode') ?: config('installer.bootstrap'));

        if (! in_array($mode, self::MODES, true)) {
            $this->error(sprintf(
                'Unknown bootstrap mode "%s". Set APP_MANAGER_BOOTSTRAP to one of: %s.',
                $mode,
                implode(', ', self::MODES),
            ));

            return self::FAILURE;
        }

        return match ($mode) {
            'installer' => $this->runInstallerMode(),
            'admin' => $this->runAdminMode($provisionFirstOwner),
            'demo' => $this->runDemoMode(),
        };
    }

    private function runInstallerMode(): int
    {
        $this->info('Bootstrap mode "installer": open '.rtrim((string) config('app.url'), '/').'/install to create the first owner.');

        return self::SUCCESS;
    }

    private function runAdminMode(ProvisionFirstOwner $provisionFirstOwner): int
    {
        // Checked before ADMIN_EMAIL/ADMIN_PASSWORD, not after: removing the
        // plaintext owner password from the host once the owner exists is the
        // right hygiene move, and it must not turn the next `docker compose up`
        // into a failing `init` that blocks the whole stack from starting.
        if (User::query()->exists()) {
            $this->info('Bootstrap mode "admin": an account already exists, leaving it untouched.');

            return self::SUCCESS;
        }

        $email = (string) config('installer.admin.email');
        $password = (string) config('installer.admin.password');

        if ($email === '') {
            $this->error('ADMIN_EMAIL is required when APP_MANAGER_BOOTSTRAP=admin.');

            return self::FAILURE;
        }

        if ($password === '') {
            $this->error('ADMIN_PASSWORD is required when APP_MANAGER_BOOTSTRAP=admin.');

            return self::FAILURE;
        }

        try {
            $user = $provisionFirstOwner->provision(
                name: (string) config('installer.admin.name'),
                email: $email,
                password: $password,
                teamName: (string) config('installer.admin.team_name'),
            );
        } catch (ValidationException $exception) {
            foreach ($exception->validator->errors()->all() as $message) {
                $this->error('ADMIN_EMAIL / ADMIN_PASSWORD rejected: '.$message);
            }

            return self::FAILURE;
        }

        $this->info(sprintf('Created owner %s with team "%s".', $user->email, (string) $user->currentTeam?->name));

        return self::SUCCESS;
    }

    private function runDemoMode(): int
    {
        // DemoSeeder itself refuses to seed outside local/development/testing.
        // Failing loudly beats a container that starts up empty but healthy.
        if (! app()->environment(['local', 'development', 'testing'])) {
            $this->error(sprintf(
                'Bootstrap mode "demo" requires APP_ENV=local (currently "%s"). The demo seeder is disabled outside local, development and testing.',
                app()->environment(),
            ));

            return self::FAILURE;
        }

        if (User::query()->exists()) {
            $this->info('Bootstrap mode "demo": an account already exists, skipping the demo seed.');

            return self::SUCCESS;
        }

        // DemoSeeder is called directly: DatabaseSeeder refuses to seed outside
        // local/development/testing, and a container runs APP_ENV=production.
        $seeded = $this->call('db:seed', ['--class' => DemoSeeder::class, '--force' => true]);

        if ($seeded !== self::SUCCESS) {
            $this->error('Bootstrap mode "demo": seeding the demo account failed.');

            return self::FAILURE;
        }

        $this->info('Seeded the demo account: demo@example.com / Demo123! (API key: demo-api-key).');

        return self::SUCCESS;
    }
}

<?php

namespace App\Http\Controllers;

use App\Actions\Fortify\CreateNewUser;
use App\Actions\Fortify\PasswordValidationRules;
use App\Enums\TeamRole;
use App\Models\Installation;
use App\Models\Team;
use App\Models\User;
use App\Support\Installation\SelfHostedConfiguration;
use App\Support\Storage\MediaStorageConfiguration;
use App\Support\Storage\MediaStorageConnectionTester;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

class InstallerController extends Controller
{
    use PasswordValidationRules;

    public function index(SelfHostedConfiguration $configuration): Response
    {
        abort_unless($configuration->enabled() && ! $configuration->installed(), 404);

        return Inertia::render('Installer/Index', [
            'requirements' => $this->requirements(),
            'urls' => [
                'install' => route('installer.store'),
                'github_callback' => route('integrations.github.callback'),
                'github_webhook' => route('webhooks.github'),
                'gitlab_callback' => route('integrations.gitlab.callback'),
            ],
            'defaults' => [
                'instance_name' => config('app.name', 'App Manager'),
                'github_api_url' => config('integrations.github.api_url', 'https://api.github.com'),
                'github_web_url' => config('integrations.github.web_url', 'https://github.com'),
                'gitlab_api_url' => config('integrations.gitlab.api_url', 'https://gitlab.com/api/v4'),
                'gitlab_web_url' => config('integrations.gitlab.web_url', 'https://gitlab.com'),
                'storage_driver' => config('media-storage.driver', 'local'),
                'storage_s3_region' => config('media-storage.s3.region') ?: 'us-east-1',
                'storage_s3_bucket' => config('media-storage.s3.bucket') ?: '',
                'storage_s3_endpoint' => config('media-storage.s3.endpoint') ?: '',
                'storage_s3_prefix' => config('media-storage.s3.prefix') ?: '',
                'storage_s3_use_path_style_endpoint' => (bool) config('media-storage.s3.use_path_style_endpoint', false),
                'storage_s3_object_acl' => config('media-storage.s3.object_acl', 'bucket-owner-full-control'),
                'storage_s3_server_side_encryption' => config('media-storage.s3.server_side_encryption') ?: '',
                'storage_s3_kms_key_id' => config('media-storage.s3.kms_key_id') ?: '',
            ],
        ]);
    }

    public function store(
        Request $request,
        CreateNewUser $createNewUser,
        SelfHostedConfiguration $configuration,
        MediaStorageConfiguration $mediaStorage,
        MediaStorageConnectionTester $storageConnectionTester,
    ): RedirectResponse {
        abort_unless($configuration->enabled() && ! $configuration->installed(), 404);
        $failedRequirements = collect($this->requirements())
            ->reject(fn (array $requirement) => $requirement['passed'])
            ->pluck('label');

        if ($failedRequirements->isNotEmpty()) {
            throw ValidationException::withMessages([
                'instance_name' => 'Server requirements are not met: '.$failedRequirements->join(', ').'.',
            ]);
        }

        $data = $request->validate($this->rules($request));
        $settings = $this->settings($data);

        try {
            $storageConnectionTester->assertAvailable(
                $mediaStorage->resolve($settings['storage']),
            );
        } catch (\RuntimeException $exception) {
            throw ValidationException::withMessages([
                'storage.driver' => $exception->getMessage(),
            ]);
        }

        $user = Cache::lock('self-hosted-installer', 15)->block(5, function () use (
            $configuration,
            $createNewUser,
            $data,
            $settings,
        ) {
            if ($configuration->installed() || User::query()->exists()) {
                throw ValidationException::withMessages([
                    'email' => 'This App Manager instance has already been installed.',
                ]);
            }

            return DB::transaction(function () use ($createNewUser, $data, $settings) {
                $user = $createNewUser->create([
                    'name' => $data['admin_name'],
                    'email' => $data['email'],
                    'password' => $data['password'],
                    'password_confirmation' => $data['password_confirmation'],
                ]);
                $team = Team::create([
                    'name' => $data['team_name'],
                    'owner_id' => $user->id,
                ]);
                $team->addUser($user, TeamRole::Owner);
                $team->recordMembershipAudit($user, 'membership_added', TeamRole::Owner->value);
                $user->update(['current_team_id' => $team->id]);

                Installation::create([
                    'id' => SelfHostedConfiguration::INSTALLATION_ID,
                    'name' => $data['instance_name'],
                    'settings' => $settings,
                    'installed_by_user_id' => $user->id,
                    'installed_at' => now(),
                ]);

                return $user;
            });
        });

        $configuration->apply();
        Auth::login($user);
        $request->session()->regenerate();

        return redirect()
            ->route('accounts.integrations.index', ['team' => $user->currentTeam])
            ->with('status', 'App Manager is ready. Connect a provider or create a webhook endpoint.');
    }

    /** @return array<string, mixed> */
    private function rules(Request $request): array
    {
        $githubEnabled = $request->boolean('github.enabled');
        $gitlabEnabled = $request->boolean('gitlab.enabled');
        $s3Enabled = $request->input('storage.driver') === 's3';
        $s3KeyPresent = filled($request->input('storage.s3.access_key_id'));
        $s3SecretPresent = filled($request->input('storage.s3.secret_access_key'));
        $s3TokenPresent = filled($request->input('storage.s3.session_token'));
        $sseKmsEnabled = $request->input('storage.s3.server_side_encryption') === 'aws:kms';
        $requiredForGitHub = Rule::requiredIf($githubEnabled);
        $requiredForGitLab = Rule::requiredIf($gitlabEnabled);
        $requiredForS3 = Rule::requiredIf($s3Enabled);

        return [
            'instance_name' => ['required', 'string', 'max:80'],
            'team_name' => ['required', 'string', 'max:255'],
            'admin_name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'string', 'email', 'max:255', Rule::unique(User::class)],
            'password' => $this->passwordRules(),
            'password_confirmation' => ['required', 'string'],

            'storage' => ['required', 'array'],
            'storage.driver' => ['required', Rule::in(['local', 's3'])],
            'storage.s3' => ['required', 'array'],
            'storage.s3.access_key_id' => [
                Rule::requiredIf($s3Enabled && ($s3SecretPresent || $s3TokenPresent)),
                'nullable',
                'string',
                'max:255',
            ],
            'storage.s3.secret_access_key' => [
                Rule::requiredIf($s3Enabled && ($s3KeyPresent || $s3TokenPresent)),
                'nullable',
                'string',
                'max:1000',
            ],
            'storage.s3.session_token' => ['nullable', 'string', 'max:4096'],
            'storage.s3.region' => [$requiredForS3, 'nullable', 'string', 'max:255'],
            'storage.s3.bucket' => [$requiredForS3, 'nullable', 'string', 'max:255'],
            'storage.s3.endpoint' => ['nullable', 'url:http,https', 'max:2048'],
            'storage.s3.prefix' => [
                'nullable',
                'string',
                'max:1024',
                'not_regex:/^\\//',
                'not_regex:/(^|\\/)\\.\\.?($|\\/)/',
            ],
            'storage.s3.use_path_style_endpoint' => ['required', 'boolean'],
            'storage.s3.object_acl' => [
                $requiredForS3,
                'nullable',
                Rule::in(['private', 'bucket-owner-full-control']),
            ],
            'storage.s3.server_side_encryption' => ['nullable', Rule::in(['AES256', 'aws:kms'])],
            'storage.s3.kms_key_id' => [
                Rule::requiredIf($s3Enabled && $sseKmsEnabled),
                'nullable',
                'string',
                'max:2048',
            ],

            'github' => ['required', 'array'],
            'github.enabled' => ['required', 'boolean'],
            'github.app_id' => [$requiredForGitHub, 'nullable', 'string', 'max:255'],
            'github.client_id' => [$requiredForGitHub, 'nullable', 'string', 'max:255'],
            'github.client_secret' => [$requiredForGitHub, 'nullable', 'string', 'max:1000'],
            'github.slug' => [$requiredForGitHub, 'nullable', 'string', 'max:255'],
            'github.private_key' => [
                $requiredForGitHub,
                'nullable',
                'string',
                'max:16384',
                function (string $attribute, mixed $value, \Closure $fail) use ($githubEnabled): void {
                    if (! $githubEnabled || ! is_string($value)) {
                        return;
                    }

                    if (! function_exists('openssl_pkey_get_private')) {
                        $fail('The OpenSSL extension is required for GitHub App authentication.');

                        return;
                    }

                    $key = openssl_pkey_get_private(str_replace('\\n', "\n", $value));

                    if ($key === false) {
                        $fail('The GitHub private key must be a valid PEM private key.');
                    }
                },
            ],
            'github.webhook_secret' => [$requiredForGitHub, 'nullable', 'string', 'min:16', 'max:1000'],
            'github.api_url' => [$requiredForGitHub, 'nullable', 'url:http,https', 'max:2048'],
            'github.web_url' => [$requiredForGitHub, 'nullable', 'url:http,https', 'max:2048'],

            'gitlab' => ['required', 'array'],
            'gitlab.enabled' => ['required', 'boolean'],
            'gitlab.client_id' => [$requiredForGitLab, 'nullable', 'string', 'max:1000'],
            'gitlab.client_secret' => [$requiredForGitLab, 'nullable', 'string', 'max:1000'],
            'gitlab.api_url' => [$requiredForGitLab, 'nullable', 'url:http,https', 'max:2048'],
            'gitlab.web_url' => [$requiredForGitLab, 'nullable', 'url:http,https', 'max:2048'],

            'webhooks' => ['required', 'array'],
            'webhooks.enabled' => ['required', 'boolean'],
            'webhooks.allow_private_addresses' => ['required', 'boolean'],
        ];
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    private function settings(array $data): array
    {
        $githubEnabled = (bool) $data['github']['enabled'];
        $gitlabEnabled = (bool) $data['gitlab']['enabled'];
        $webhooksEnabled = (bool) $data['webhooks']['enabled'];

        return [
            'storage' => $this->storageSettings($data),
            'integrations' => [
                'enabled' => $githubEnabled || $gitlabEnabled || $webhooksEnabled,
                'github' => [
                    'enabled' => $githubEnabled,
                    'app_id' => $githubEnabled ? $data['github']['app_id'] : null,
                    'client_id' => $githubEnabled ? $data['github']['client_id'] : null,
                    'client_secret' => $githubEnabled ? $data['github']['client_secret'] : null,
                    'slug' => $githubEnabled ? $data['github']['slug'] : null,
                    'private_key' => $githubEnabled ? str_replace('\\n', "\n", $data['github']['private_key']) : null,
                    'webhook_secret' => $githubEnabled ? $data['github']['webhook_secret'] : null,
                    'api_url' => ($data['github']['api_url'] ?? null) ?: 'https://api.github.com',
                    'web_url' => ($data['github']['web_url'] ?? null) ?: 'https://github.com',
                ],
                'gitlab' => [
                    'enabled' => $gitlabEnabled,
                    'client_id' => $gitlabEnabled ? $data['gitlab']['client_id'] : null,
                    'client_secret' => $gitlabEnabled ? $data['gitlab']['client_secret'] : null,
                    'api_url' => ($data['gitlab']['api_url'] ?? null) ?: 'https://gitlab.com/api/v4',
                    'web_url' => ($data['gitlab']['web_url'] ?? null) ?: 'https://gitlab.com',
                    'scopes' => ['api', 'openid'],
                ],
                'webhooks' => [
                    'enabled' => $webhooksEnabled,
                    'allow_private_addresses' => (bool) $data['webhooks']['allow_private_addresses'],
                ],
            ],
        ];
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    private function storageSettings(array $data): array
    {
        $driver = $data['storage']['driver'];

        if ($driver === 'local') {
            return ['driver' => 'local'];
        }

        $s3 = $data['storage']['s3'];

        return [
            'driver' => 's3',
            's3' => [
                'access_key_id' => $s3['access_key_id'] ?? null,
                'secret_access_key' => $s3['secret_access_key'] ?? null,
                'session_token' => $s3['session_token'] ?? null,
                'region' => $s3['region'],
                'bucket' => $s3['bucket'],
                'endpoint' => $s3['endpoint'] ?? null,
                'url' => null,
                'prefix' => $s3['prefix'] ?? null,
                'use_path_style_endpoint' => (bool) $s3['use_path_style_endpoint'],
                'object_acl' => $s3['object_acl'],
                'server_side_encryption' => $s3['server_side_encryption'] ?? null,
                'kms_key_id' => $s3['kms_key_id'] ?? null,
            ],
        ];
    }

    /** @return list<array{label: string, passed: bool, detail: string}> */
    private function requirements(): array
    {
        $databaseAvailable = $this->databaseAvailable();

        $checks = [
            [
                'label' => 'PHP 8.3 or newer',
                'passed' => version_compare(PHP_VERSION, '8.3.0', '>='),
                'detail' => 'Running PHP '.PHP_VERSION,
            ],
            [
                'label' => 'OpenSSL extension',
                'passed' => extension_loaded('openssl'),
                'detail' => extension_loaded('openssl') ? 'Available' : 'Required for encryption and GitHub App authentication',
            ],
            [
                'label' => 'PDO database connection',
                'passed' => extension_loaded('pdo') && $databaseAvailable,
                'detail' => $databaseAvailable ? 'Connected' : 'Could not connect to the configured database',
            ],
            [
                'label' => 'Application encryption key',
                'passed' => is_string(config('app.key')) && config('app.key') !== '',
                'detail' => config('app.key') ? 'Configured' : 'Run php artisan key:generate',
            ],
            [
                'label' => 'Writable storage',
                'passed' => is_writable(storage_path()) && is_writable(storage_path('framework')),
                'detail' => is_writable(storage_path()) ? storage_path() : 'Storage directory is not writable',
            ],
            [
                'label' => 'Database migrations',
                'passed' => Schema::hasTable('installations') && Schema::hasTable('users') && Schema::hasTable('teams'),
                'detail' => Schema::hasTable('installations') ? 'Up to date' : 'Run php artisan migrate --force',
            ],
        ];

        return $checks;
    }

    private function databaseAvailable(): bool
    {
        try {
            DB::connection()->getPdo();

            return true;
        } catch (\Throwable) {
            return false;
        }
    }
}

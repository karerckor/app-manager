<?php

namespace App\Providers;

use App\Integrations\CiCd\CiCdProviderRegistry;
use App\Integrations\CiCd\Contracts\OAuthCredentialStore;
use App\Integrations\CiCd\DatabaseOAuthCredentialStore;
use App\Integrations\CiCd\Expo\ExpoProvider;
use App\Integrations\CiCd\GitHub\GitHubProvider;
use App\Integrations\CiCd\GitLab\GitLabProvider;
use App\Models\ApiKey;
use App\Models\Team;
use App\Support\Audit\ActorContextManager;
use App\Support\Installation\SelfHostedConfiguration;
use App\Support\Storage\MediaStorageConfiguration;
use Illuminate\Queue\Events\JobProcessing;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\ServiceProvider;
use Laravel\Sanctum\Sanctum;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->app->singleton(OAuthCredentialStore::class, DatabaseOAuthCredentialStore::class);

        $this->app->singleton(CiCdProviderRegistry::class, function ($app) {
            return new CiCdProviderRegistry([
                $app->make(GitHubProvider::class),
                $app->make(GitLabProvider::class),
                $app->make(ExpoProvider::class),
            ]);
        });

        $this->app->singleton(ActorContextManager::class);
        $this->app->singleton(MediaStorageConfiguration::class);
        $this->app->singleton(SelfHostedConfiguration::class);
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(
        MediaStorageConfiguration $mediaStorageConfiguration,
        SelfHostedConfiguration $selfHostedConfiguration,
    ): void {
        // `config:cache` var_exports the whole config repository to
        // bootstrap/cache/config.php. SelfHostedConfiguration::apply() pushes
        // the installer's settings into that repository, and those settings are
        // an `encrypted:array` cast — they arrive decrypted, so caching them
        // would write the GitHub App private key, the OAuth client secrets, the
        // webhook secret and the S3 credentials to a plaintext file next to an
        // encrypted database that holds the same values. Skip both appliers
        // while the cache is being built; the cached file then holds only
        // environment-derived configuration.
        //
        // Do not "clean this up": these calls still run on every real boot
        // (including a boot that reads the cached config) and before every
        // queued job, so nothing about runtime behaviour depends on them
        // being baked into the cache.
        if (! $this->app->runningConsoleCommand('config:cache', 'optimize')) {
            $mediaStorageConfiguration->apply();
            $selfHostedConfiguration->apply();
        }

        Queue::before(fn (JobProcessing $event) => $selfHostedConfiguration->apply());
        Sanctum::usePersonalAccessTokenModel(ApiKey::class);
    }
}

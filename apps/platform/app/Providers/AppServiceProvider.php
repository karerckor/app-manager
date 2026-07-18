<?php

namespace App\Providers;

use App\Integrations\CiCd\CiCdProviderRegistry;
use App\Integrations\CiCd\GitHub\GitHubProvider;
use App\Models\ApiKey;
use Illuminate\Support\ServiceProvider;
use Laravel\Sanctum\Sanctum;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->app->singleton(CiCdProviderRegistry::class, function ($app) {
            return new CiCdProviderRegistry([
                $app->make(GitHubProvider::class),
            ]);
        });
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        Sanctum::usePersonalAccessTokenModel(ApiKey::class);
    }
}

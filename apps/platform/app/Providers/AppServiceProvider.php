<?php

namespace App\Providers;

use App\Billing\BillingConfiguration;
use App\Billing\CashierMeterEventReporter;
use App\Billing\Contracts\AuthorizesBuildCreation;
use App\Billing\Contracts\RecordsUsage;
use App\Billing\Contracts\ReportsMeterEvents;
use App\Billing\NullUsageRecorder;
use App\Billing\OutboxUsageRecorder;
use App\Billing\SubscribedBuildAuthorizer;
use App\Billing\UnlimitedBuildAuthorizer;
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
use Laravel\Cashier\Cashier;
use Laravel\Sanctum\Sanctum;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        Cashier::ignoreRoutes();

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
        $this->app->singleton(ReportsMeterEvents::class, CashierMeterEventReporter::class);
        $this->app->singleton(
            AuthorizesBuildCreation::class,
            fn ($app) => $app->make(BillingConfiguration::enabled()
                ? SubscribedBuildAuthorizer::class
                : UnlimitedBuildAuthorizer::class),
        );
        $this->app->singleton(
            RecordsUsage::class,
            fn ($app) => $app->make(BillingConfiguration::enabled()
                ? OutboxUsageRecorder::class
                : NullUsageRecorder::class),
        );
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(
        MediaStorageConfiguration $mediaStorageConfiguration,
        SelfHostedConfiguration $selfHostedConfiguration,
    ): void {
        BillingConfiguration::assertEnvironment();
        $mediaStorageConfiguration->apply();
        $selfHostedConfiguration->apply();
        Queue::before(fn (JobProcessing $event) => $selfHostedConfiguration->apply());
        Cashier::useCustomerModel(Team::class);
        Sanctum::usePersonalAccessTokenModel(ApiKey::class);
    }
}

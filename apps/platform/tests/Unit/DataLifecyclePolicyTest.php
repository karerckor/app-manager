<?php

namespace Tests\Unit;

use App\Models\ApiKey;
use App\Models\AppIdentifier;
use App\Models\Artifact;
use App\Models\Audit;
use App\Models\Blueprint;
use App\Models\BlueprintHook;
use App\Models\BuildRecord;
use App\Models\CiCdPipeline;
use App\Models\CiCdRun;
use App\Models\Credential;
use App\Models\CredentialVaultReference;
use App\Models\EncryptionKey;
use App\Models\ExpoCredentialLink;
use App\Models\ExpoProject;
use App\Models\GitHubWebhookDelivery;
use App\Models\Installation;
use App\Models\Integration;
use App\Models\IntegrationOAuthCredential;
use App\Models\MobileApp;
use App\Models\NotificationDelivery;
use App\Models\NotificationDestination;
use App\Models\Profile;
use App\Models\SentryDsn;
use App\Models\SentryDsnRoute;
use App\Models\SentryEnvelope;
use App\Models\SentryEvent;
use App\Models\SentryIssue;
use App\Models\Team;
use App\Models\TeamInvitation;
use App\Models\User;
use App\Models\WebhookDelivery;
use App\Models\WebhookEndpoint;
use App\Models\WebhookEvent;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Facades\File;
use Tests\TestCase;

class DataLifecyclePolicyTest extends TestCase
{
    /** @var list<class-string> */
    private array $recoverable = [
        AppIdentifier::class,
        Artifact::class,
        Blueprint::class,
        BlueprintHook::class,
        BuildRecord::class,
        CiCdPipeline::class,
        Credential::class,
        Integration::class,
        MobileApp::class,
        NotificationDestination::class,
        Profile::class,
        SentryDsn::class,
        SentryIssue::class,
        Team::class,
        User::class,
        WebhookEndpoint::class,
    ];

    /**
     * Append-only history with explicit retention, dependent rows whose lifecycle
     * follows a recoverable parent, and security/system records that must be
     * revoked or rotated rather than restored.
     *
     * @var list<class-string>
     */
    private array $intentionallyHardDeleted = [
        ApiKey::class,
        Audit::class,
        CiCdRun::class,
        CredentialVaultReference::class,
        EncryptionKey::class,
        ExpoCredentialLink::class,
        ExpoProject::class,
        GitHubWebhookDelivery::class,
        Installation::class,
        IntegrationOAuthCredential::class,
        NotificationDelivery::class,
        SentryDsnRoute::class,
        SentryEnvelope::class,
        SentryEvent::class,
        TeamInvitation::class,
        WebhookDelivery::class,
        WebhookEvent::class,
    ];

    public function test_every_application_model_has_an_explicit_lifecycle_policy(): void
    {
        $actual = collect(File::files(app_path('Models')))
            ->map(fn ($file): string => 'App\\Models\\'.$file->getFilenameWithoutExtension())
            ->sort()
            ->values()
            ->all();
        $classified = collect([...$this->recoverable, ...$this->intentionallyHardDeleted])
            ->sort()
            ->values()
            ->all();

        $this->assertSame($actual, $classified);
    }

    public function test_only_recoverable_models_use_soft_deletes(): void
    {
        foreach ($this->recoverable as $modelClass) {
            $this->assertContains(SoftDeletes::class, class_uses_recursive($modelClass), $modelClass);
        }

        foreach ($this->intentionallyHardDeleted as $modelClass) {
            $this->assertNotContains(SoftDeletes::class, class_uses_recursive($modelClass), $modelClass);
        }
    }
}

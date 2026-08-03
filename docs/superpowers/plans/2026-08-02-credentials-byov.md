# Credentials BYOV Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Let a credential hold a *reference* to a secret in the team's own vault instead of the secret itself, governed by an instance/team storage-mode policy, plus a `dashboard-only` deployment profile in which secret material never traverses the API.

**Architecture:** One new column on `credentials` (`storage_mode`, immutable after creation) and one 1:1 side table `credential_vault_references` holding the address, storage metadata and verification state. Validation branches on mode inside `CredentialSchema`; the branch rule is "no file, and no fields whose schema `input` is `password`", which needs no new per-type data. A pipeline reports back to a new `/verification` endpoint. The `dashboard-only` profile is a single config value read once at route-load time.

**Tech Stack:** Laravel 12 / PHP 8.3, PostgreSQL, PHPUnit (class-style, not Pest), Inertia + Svelte 5, Spatie MediaLibrary, `owen-it/laravel-auditing`, bash CLI (`libs/cli`), Ruby fastlane plugin (`libs/fastlane-plugin`).

**Spec:** `docs/superpowers/specs/2026-08-02-credentials-byov-design.md`

## Global Constraints

- All PHP work happens in `apps/platform`. Run tests from there: `php artisan test --filter=<Name>`.
- Tests are **PHPUnit class-style**, extending `Tests\TestCase`, using `Illuminate\Foundation\Testing\RefreshDatabase`. Do not write Pest.
- Test method names are long snake_case sentences (`test_external_credential_rejects_an_uploaded_file`). Match the surrounding files.
- Database is PostgreSQL. Use `jsonb` for JSON columns.
- Every string constant that has a closed value set becomes a PHP backed enum in `app/Enums`.
- Models use `#[Fillable([...])]` attribute + `protected function casts(): array`. Do not add `$fillable`/`$casts` properties.
- Existing behaviour for `storage_mode = 'local'` must be byte-for-byte unchanged. Any test that currently passes must still pass.
- New/edited Svelte components use **runes** (`$props()`, `$state()`, `$derived`). Do not add new `export let`.
- Commit after every task with a conventional-commit subject.

---

## File Structure

**New PHP**

| File | Responsibility |
|---|---|
| `app/Enums/CredentialStorageMode.php` | `local` \| `external` |
| `app/Enums/VaultProvider.php` | closed provider list + label + resource placeholder |
| `app/Enums/CredentialVerificationStatus.php` | `unverified` \| `verified` \| `failed` |
| `app/Enums/VaultMetadataSource.php` | `user` \| `pipeline` \| `import` |
| `app/Enums/CredentialApiProfile.php` | `full` \| `dashboard_only` + `exposesSecretMaterial()` |
| `config/credentials.php` | `storage_modes`, `api_profile` |
| `app/Support/Credentials/StorageModePolicy.php` | the only resolver of "which modes may this team use" |
| `app/Support/Credentials/CredentialRequestRules.php` | the storage-mode half of request validation, shared by the web and API controllers |
| `app/Models/CredentialVaultReference.php` | the 1:1 side table |
| `app/Actions/Credentials/SyncVaultReference.php` | write/refresh a reference from request input |
| `app/Actions/Credentials/RecordVaultVerification.php` | apply a pipeline report + emit the transition webhook |
| `app/Http/Controllers/Api/V1/CredentialVerificationController.php` | `POST …/verification` |
| `database/factories/CredentialVaultReferenceFactory.php` | test data |

**Modified PHP**

`app/Models/Credential.php`, `app/Models/Team.php`, `app/Enums/WebhookEventType.php`, `app/Enums/ApiKeyScope.php`, `app/Support/Credentials/CredentialSchema.php`, `app/Support/Credentials/CredentialUniqueness.php`, `app/Support/Apps/CredentialPresenter.php`, `app/Http/Controllers/CredentialController.php`, `app/Http/Controllers/CredentialVaultController.php`, `app/Http/Controllers/Api/V1/CredentialController.php`, `app/Http/Resources/Api/V1/CredentialResource.php`, `app/Integrations/Webhooks/WebhookEventRecorder.php`, `app/Integrations/Webhooks/WebhookPayload.php`, `app/Http/Controllers/TeamController.php`, `routes/api.php`, `database/factories/CredentialFactory.php`.

**Frontend**

`resources/js/Pages/Credentials/VaultCredentialFormModal.svelte`, `resources/js/Pages/Apps/Modals/CredentialFormModal.svelte`, `resources/js/Pages/Credentials/Index.svelte`, `resources/js/Pages/Team/Index.svelte`, plus a new `resources/js/Components/VaultReferenceFields.svelte` shared by both modals.

**Outside the platform**

`libs/cli/lib/commands/credentials.sh`, `libs/cli/tests/test_credentials.sh`, `libs/fastlane-plugin/lib/fastlane/plugin/app_manager/actions/app_manager_list_credentials_action.rb`, a new `…/app_manager_report_credential_verification_action.rb`, and docs under `apps/docs`.

---

## Task 1: Enums, config, `storage_mode` column, storage-mode policy

**Files:**
- Create: `apps/platform/app/Enums/CredentialStorageMode.php`
- Create: `apps/platform/app/Enums/VaultProvider.php`
- Create: `apps/platform/app/Enums/CredentialVerificationStatus.php`
- Create: `apps/platform/app/Enums/VaultMetadataSource.php`
- Create: `apps/platform/config/credentials.php`
- Create: `apps/platform/database/migrations/2026_08_02_100000_add_credential_storage_modes.php`
- Create: `apps/platform/app/Support/Credentials/StorageModePolicy.php`
- Modify: `apps/platform/app/Models/Credential.php` (the `#[Fillable]` attribute and `casts()`)
- Modify: `apps/platform/app/Models/Team.php` (the `#[Fillable]` attribute and `casts()`)
- Modify: `apps/platform/.env.example`
- Test: `apps/platform/tests/Feature/CredentialStorageModePolicyTest.php`

**Interfaces:**
- Consumes: nothing.
- Produces:
  - `CredentialStorageMode::Local`, `::External`, `->label(): string`, `::values(): list<string>`
  - `VaultProvider::AwsSecretsManager|HashicorpVault|AzureKeyVault|GcpSecretManager|FastlaneMatch|Custom`, `->label(): string`, `->resourcePlaceholder(): string`, `::definitions(): list<array{value:string,label:string,placeholder:string}>`
  - `CredentialVerificationStatus::Unverified|Verified|Failed`
  - `VaultMetadataSource::User|Pipeline|Import`
  - `StorageModePolicy::instanceModes(): list<CredentialStorageMode>`, `->allowedFor(Team): list<CredentialStorageMode>`, `->allowedValuesFor(Team): list<string>`, `->permits(Team, CredentialStorageMode): bool`, `->defaultFor(Team): CredentialStorageMode`
  - `Credential->storage_mode` cast to `CredentialStorageMode`
  - `Team->allowed_credential_storage_modes` cast to `?array`

- [ ] **Step 1: Write the failing test**

Create `apps/platform/tests/Feature/CredentialStorageModePolicyTest.php`:

```php
<?php

namespace Tests\Feature;

use App\Enums\CredentialStorageMode;
use App\Models\Team;
use App\Support\Credentials\StorageModePolicy;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CredentialStorageModePolicyTest extends TestCase
{
    use RefreshDatabase;

    public function test_instance_ceiling_is_parsed_from_config(): void
    {
        config(['credentials.storage_modes' => 'local, external']);

        $this->assertSame(
            [CredentialStorageMode::Local, CredentialStorageMode::External],
            $this->policy()->instanceModes(),
        );
    }

    public function test_unparseable_config_falls_back_to_local(): void
    {
        config(['credentials.storage_modes' => 'nonsense,,']);

        $this->assertSame([CredentialStorageMode::Local], $this->policy()->instanceModes());
    }

    public function test_null_team_setting_inherits_the_ceiling(): void
    {
        config(['credentials.storage_modes' => 'local,external']);
        $team = Team::factory()->create(['allowed_credential_storage_modes' => null]);

        $this->assertSame(
            ['local', 'external'],
            $this->policy()->allowedValuesFor($team),
        );
    }

    public function test_team_narrows_the_instance_ceiling(): void
    {
        config(['credentials.storage_modes' => 'local,external']);
        $team = Team::factory()->create(['allowed_credential_storage_modes' => ['external']]);

        $this->assertSame(['external'], $this->policy()->allowedValuesFor($team));
        $this->assertFalse($this->policy()->permits($team, CredentialStorageMode::Local));
        $this->assertTrue($this->policy()->permits($team, CredentialStorageMode::External));
    }

    public function test_team_cannot_widen_beyond_the_ceiling(): void
    {
        config(['credentials.storage_modes' => 'local']);
        $team = Team::factory()->create([
            'allowed_credential_storage_modes' => ['local', 'external'],
        ]);

        $this->assertSame(['local'], $this->policy()->allowedValuesFor($team));
    }

    public function test_empty_intersection_falls_back_to_the_ceiling(): void
    {
        config(['credentials.storage_modes' => 'local']);
        $team = Team::factory()->create(['allowed_credential_storage_modes' => ['external']]);

        $this->assertSame(['local'], $this->policy()->allowedValuesFor($team));
        $this->assertSame(CredentialStorageMode::Local, $this->policy()->defaultFor($team));
    }

    private function policy(): StorageModePolicy
    {
        return new StorageModePolicy;
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `cd apps/platform && php artisan test --filter=CredentialStorageModePolicyTest`
Expected: FAIL — `Class "App\Enums\CredentialStorageMode" not found`.

- [ ] **Step 3: Create the four enums**

`app/Enums/CredentialStorageMode.php`:

```php
<?php

namespace App\Enums;

enum CredentialStorageMode: string
{
    case Local = 'local';
    case External = 'external';

    public function label(): string
    {
        return match ($this) {
            self::Local => 'Stored in App Manager',
            self::External => 'Referenced in your vault',
        };
    }

    /** @return list<string> */
    public static function values(): array
    {
        return array_map(fn (self $mode): string => $mode->value, self::cases());
    }
}
```

`app/Enums/VaultProvider.php`:

```php
<?php

namespace App\Enums;

enum VaultProvider: string
{
    case AwsSecretsManager = 'aws_secrets_manager';
    case HashicorpVault = 'hashicorp_vault';
    case AzureKeyVault = 'azure_key_vault';
    case GcpSecretManager = 'gcp_secret_manager';
    case FastlaneMatch = 'fastlane_match';
    case Custom = 'custom';

    public function label(): string
    {
        return match ($this) {
            self::AwsSecretsManager => 'AWS Secrets Manager',
            self::HashicorpVault => 'HashiCorp Vault',
            self::AzureKeyVault => 'Azure Key Vault',
            self::GcpSecretManager => 'Google Secret Manager',
            self::FastlaneMatch => 'fastlane match',
            self::Custom => 'Custom',
        };
    }

    public function resourcePlaceholder(): string
    {
        return match ($this) {
            self::AwsSecretsManager => 'arn:aws:secretsmanager:eu-central-1:123456789012:secret:name-AbCdEf',
            self::HashicorpVault => 'secret/data/mobile/ios-distribution',
            self::AzureKeyVault => 'https://my-vault.vault.azure.net/secrets/ios-distribution',
            self::GcpSecretManager => 'projects/my-project/secrets/ios-distribution/versions/latest',
            self::FastlaneMatch => 'git@github.com:acme/certificates.git#appstore/com.acme.app',
            self::Custom => 'Any address your pipeline understands',
        };
    }

    /** @return list<array{value: string, label: string, placeholder: string}> */
    public static function definitions(): array
    {
        return array_map(fn (self $provider): array => [
            'value' => $provider->value,
            'label' => $provider->label(),
            'placeholder' => $provider->resourcePlaceholder(),
        ], self::cases());
    }
}
```

`app/Enums/CredentialVerificationStatus.php`:

```php
<?php

namespace App\Enums;

enum CredentialVerificationStatus: string
{
    case Unverified = 'unverified';
    case Verified = 'verified';
    case Failed = 'failed';

    public function label(): string
    {
        return match ($this) {
            self::Unverified => 'Not verified',
            self::Verified => 'Verified',
            self::Failed => 'Verification failed',
        };
    }
}
```

`app/Enums/VaultMetadataSource.php`:

```php
<?php

namespace App\Enums;

enum VaultMetadataSource: string
{
    case User = 'user';
    case Pipeline = 'pipeline';
    case Import = 'import';
}
```

- [ ] **Step 4: Create the config file**

`config/credentials.php`:

```php
<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Permitted credential storage modes
    |--------------------------------------------------------------------------
    |
    | The instance-wide ceiling. A team may narrow this set but never widen it.
    | "local" stores the secret in App Manager; "external" stores only a
    | reference to the secret in the team's own vault.
    |
    */

    'storage_modes' => env('CREDENTIAL_STORAGE_MODES', 'local,external'),

    /*
    |--------------------------------------------------------------------------
    | Credential API profile
    |--------------------------------------------------------------------------
    |
    | "full" exposes every credential endpoint. "dashboard_only" does not
    | register the endpoints that move secret material, and suppresses
    | credential webhooks and notifications. Switching profiles is a deploy,
    | not a runtime toggle: routes are resolved once, at boot.
    |
    */

    'api_profile' => env('CREDENTIALS_API_PROFILE', 'full'),

];
```

Append to `.env.example`, after the existing credential-related block:

```
CREDENTIAL_STORAGE_MODES=local,external
CREDENTIALS_API_PROFILE=full
```

- [ ] **Step 5: Create the migration**

`database/migrations/2026_08_02_100000_add_credential_storage_modes.php`:

```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('credentials', function (Blueprint $table) {
            $table->string('storage_mode')->default('local');
            $table->index(['team_id', 'storage_mode'], 'credentials_storage_mode_lookup');
        });

        Schema::table('teams', function (Blueprint $table) {
            $table->jsonb('allowed_credential_storage_modes')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('credentials', function (Blueprint $table) {
            $table->dropIndex('credentials_storage_mode_lookup');
            $table->dropColumn('storage_mode');
        });

        Schema::table('teams', function (Blueprint $table) {
            $table->dropColumn('allowed_credential_storage_modes');
        });
    }
};
```

The `default('local')` backfills every existing row; no data migration is needed.

- [ ] **Step 6: Wire the models**

In `app/Models/Credential.php`, change the attribute and the casts:

```php
#[Fillable(['team_id', 'type', 'storage_mode', 'name', 'payload', 'expires_at', 'tags', 'fingerprint'])]
```

```php
    protected function casts(): array
    {
        return [
            'type' => CredentialType::class,
            'storage_mode' => CredentialStorageMode::class,
            'payload' => EncryptedArrayWithDEK::class,
            'expires_at' => 'datetime',
        ];
    }
```

Add `use App\Enums\CredentialStorageMode;` to the imports.

In `app/Models/Team.php`:

```php
#[Fillable(['name', 'slug', 'owner_id', 'check_credential_uniqueness_by_default', 'allowed_credential_storage_modes'])]
```

```php
    protected function casts(): array
    {
        return [
            'check_credential_uniqueness_by_default' => 'boolean',
            'allowed_credential_storage_modes' => 'array',
        ];
    }
```

- [ ] **Step 7: Create `StorageModePolicy`**

`app/Support/Credentials/StorageModePolicy.php`:

```php
<?php

namespace App\Support\Credentials;

use App\Enums\CredentialStorageMode;
use App\Models\Team;

class StorageModePolicy
{
    /** @return list<CredentialStorageMode> */
    public function instanceModes(): array
    {
        $modes = collect(explode(',', (string) config('credentials.storage_modes')))
            ->map(fn (string $mode): ?CredentialStorageMode => CredentialStorageMode::tryFrom(trim($mode)))
            ->filter()
            ->unique()
            ->values()
            ->all();

        return $modes === [] ? [CredentialStorageMode::Local] : $modes;
    }

    /** @return list<CredentialStorageMode> */
    public function allowedFor(Team $team): array
    {
        $ceiling = $this->instanceModes();
        $chosen = $team->allowed_credential_storage_modes;

        if (! is_array($chosen)) {
            return $ceiling;
        }

        $selected = collect($chosen)
            ->map(fn ($mode): ?CredentialStorageMode => CredentialStorageMode::tryFrom((string) $mode))
            ->filter();

        $intersection = collect($ceiling)
            ->filter(fn (CredentialStorageMode $mode): bool => $selected->contains($mode))
            ->values()
            ->all();

        // An empty intersection would lock the team out of creating any
        // credential at all, with no in-app way to recover. The instance
        // ceiling stays authoritative instead.
        return $intersection === [] ? $ceiling : $intersection;
    }

    /** @return list<string> */
    public function allowedValuesFor(Team $team): array
    {
        return array_map(
            fn (CredentialStorageMode $mode): string => $mode->value,
            $this->allowedFor($team),
        );
    }

    public function permits(Team $team, CredentialStorageMode $mode): bool
    {
        return in_array($mode, $this->allowedFor($team), true);
    }

    public function defaultFor(Team $team): CredentialStorageMode
    {
        return $this->allowedFor($team)[0];
    }
}
```

- [ ] **Step 8: Run the test to verify it passes**

Run: `cd apps/platform && php artisan test --filter=CredentialStorageModePolicyTest`
Expected: PASS, 6 tests.

- [ ] **Step 9: Run the full credential suite for regressions**

Run: `cd apps/platform && php artisan test --filter=Credential`
Expected: PASS — the new column has a default, so nothing existing changes.

- [ ] **Step 10: Commit**

```bash
git add apps/platform/app/Enums apps/platform/config/credentials.php \
  apps/platform/database/migrations/2026_08_02_100000_add_credential_storage_modes.php \
  apps/platform/app/Support/Credentials/StorageModePolicy.php \
  apps/platform/app/Models/Credential.php apps/platform/app/Models/Team.php \
  apps/platform/.env.example apps/platform/tests/Feature/CredentialStorageModePolicyTest.php
git commit -m "feat(credentials): add storage modes and the storage-mode policy"
```

---

## Task 2: `credential_vault_references` table, model, factories

**Files:**
- Create: `apps/platform/database/migrations/2026_08_02_100100_create_credential_vault_references_table.php`
- Create: `apps/platform/app/Models/CredentialVaultReference.php`
- Create: `apps/platform/database/factories/CredentialVaultReferenceFactory.php`
- Modify: `apps/platform/app/Models/Credential.php`
- Modify: `apps/platform/database/factories/CredentialFactory.php`
- Test: `apps/platform/tests/Feature/CredentialVaultReferenceTest.php`

**Interfaces:**
- Consumes: `CredentialStorageMode`, `VaultProvider`, `CredentialVerificationStatus`, `VaultMetadataSource` (Task 1).
- Produces:
  - `CredentialVaultReference` with `credential()`, `team()` relations and the columns from the spec
  - `Credential->vaultReference(): HasOne`
  - `Credential->isExternal(): bool`
  - `CredentialFactory::external(?VaultProvider $provider = null, ?string $resource = null): static`

- [ ] **Step 1: Write the failing test**

Create `apps/platform/tests/Feature/CredentialVaultReferenceTest.php`:

```php
<?php

namespace Tests\Feature;

use App\Enums\CredentialStorageMode;
use App\Enums\CredentialVerificationStatus;
use App\Enums\VaultMetadataSource;
use App\Enums\VaultProvider;
use App\Models\Credential;
use App\Models\CredentialVaultReference;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CredentialVaultReferenceTest extends TestCase
{
    use RefreshDatabase;

    public function test_factory_state_creates_an_external_credential_with_a_reference(): void
    {
        $credential = Credential::factory()
            ->external(VaultProvider::HashicorpVault, 'secret/data/ios')
            ->create();

        $this->assertSame(CredentialStorageMode::External, $credential->storage_mode);
        $this->assertTrue($credential->isExternal());

        $reference = $credential->vaultReference;
        $this->assertNotNull($reference);
        $this->assertSame(VaultProvider::HashicorpVault, $reference->provider);
        $this->assertSame('secret/data/ios', $reference->resource);
        $this->assertSame(CredentialVerificationStatus::Unverified, $reference->verification_status);
        $this->assertSame(VaultMetadataSource::User, $reference->metadata_source);
        $this->assertSame(1, $reference->metadata_version);
        $this->assertSame($credential->team_id, $reference->team_id);
    }

    public function test_local_credential_has_no_reference(): void
    {
        $credential = Credential::factory()->create();

        $this->assertSame(CredentialStorageMode::Local, $credential->storage_mode);
        $this->assertFalse($credential->isExternal());
        $this->assertNull($credential->vaultReference);
    }

    public function test_deleting_the_credential_deletes_the_reference(): void
    {
        $credential = Credential::factory()->external()->create();
        $referenceId = $credential->vaultReference->id;

        $credential->delete();

        $this->assertDatabaseMissing('credential_vault_references', ['id' => $referenceId]);
    }

    public function test_a_credential_can_only_hold_one_reference(): void
    {
        $credential = Credential::factory()->external()->create();

        $this->expectException(\Illuminate\Database\QueryException::class);

        CredentialVaultReference::create([
            'credential_id' => $credential->id,
            'team_id' => $credential->team_id,
            'provider' => VaultProvider::Custom,
            'resource' => 'second-one',
            'metadata_source' => VaultMetadataSource::User,
            'verification_status' => CredentialVerificationStatus::Unverified,
        ]);
    }

    public function test_reference_changes_are_audited(): void
    {
        $credential = Credential::factory()->external()->create();

        $credential->vaultReference->update([
            'verification_status' => CredentialVerificationStatus::Verified,
            'verified_at' => now(),
        ]);

        $this->assertDatabaseHas('audits', [
            'auditable_type' => CredentialVaultReference::class,
            'auditable_id' => $credential->vaultReference->id,
            'event' => 'updated',
        ]);
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `cd apps/platform && php artisan test --filter=CredentialVaultReferenceTest`
Expected: FAIL — `Call to undefined method …CredentialFactory::external()`.

- [ ] **Step 3: Create the migration**

`database/migrations/2026_08_02_100100_create_credential_vault_references_table.php`:

```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('credential_vault_references', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('credential_id')->unique()->constrained()->cascadeOnDelete();
            $table->foreignUuid('team_id')->constrained()->cascadeOnDelete();
            $table->string('provider');                 // VaultProvider enum
            $table->text('resource');                   // ARN / path / URI, deliberately not encrypted
            $table->jsonb('metadata')->nullable();      // storage metadata only
            $table->unsignedInteger('metadata_version')->default(1);
            $table->string('metadata_source')->default('user');
            $table->string('verification_status')->default('unverified');
            $table->timestamp('verified_at')->nullable();
            $table->text('verification_error')->nullable();
            $table->timestamps();

            $table->index(['team_id', 'provider', 'resource'], 'vault_references_uniqueness_lookup');
            $table->index(['team_id', 'verification_status'], 'vault_references_status_lookup');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('credential_vault_references');
    }
};
```

On PostgreSQL an index over a `text` column is fine at these row counts; do not add a length prefix.

- [ ] **Step 4: Create the model**

`app/Models/CredentialVaultReference.php`:

```php
<?php

namespace App\Models;

use App\Enums\CredentialVerificationStatus;
use App\Enums\VaultMetadataSource;
use App\Enums\VaultProvider;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use OwenIt\Auditing\Auditable as AuditableTrait;
use OwenIt\Auditing\Contracts\Auditable;

#[Fillable([
    'credential_id',
    'team_id',
    'provider',
    'resource',
    'metadata',
    'metadata_version',
    'metadata_source',
    'verification_status',
    'verified_at',
    'verification_error',
])]
class CredentialVaultReference extends Model implements Auditable
{
    use AuditableTrait, HasFactory, HasUuids;

    public function credential(): BelongsTo
    {
        return $this->belongsTo(Credential::class);
    }

    public function team(): BelongsTo
    {
        return $this->belongsTo(Team::class);
    }

    protected function casts(): array
    {
        return [
            'provider' => VaultProvider::class,
            'metadata' => 'array',
            'metadata_version' => 'integer',
            'metadata_source' => VaultMetadataSource::class,
            'verification_status' => CredentialVerificationStatus::class,
            'verified_at' => 'datetime',
        ];
    }
}
```

- [ ] **Step 5: Add the relation and helper to `Credential`**

In `app/Models/Credential.php` add the import `use Illuminate\Database\Eloquent\Relations\HasOne;` and:

```php
    public function vaultReference(): HasOne
    {
        return $this->hasOne(CredentialVaultReference::class);
    }

    public function isExternal(): bool
    {
        return $this->storage_mode === CredentialStorageMode::External;
    }
```

- [ ] **Step 6: Create the factories**

`database/factories/CredentialVaultReferenceFactory.php`:

```php
<?php

namespace Database\Factories;

use App\Enums\CredentialVerificationStatus;
use App\Enums\VaultMetadataSource;
use App\Enums\VaultProvider;
use App\Models\CredentialVaultReference;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<CredentialVaultReference>
 */
class CredentialVaultReferenceFactory extends Factory
{
    public function definition(): array
    {
        return [
            'provider' => VaultProvider::AwsSecretsManager,
            'resource' => 'arn:aws:secretsmanager:eu-central-1:123456789012:secret:'.fake()->slug(2),
            'metadata' => null,
            'metadata_version' => 1,
            'metadata_source' => VaultMetadataSource::User,
            'verification_status' => CredentialVerificationStatus::Unverified,
            'verified_at' => null,
            'verification_error' => null,
        ];
    }
}
```

In `database/factories/CredentialFactory.php` add the `external()` state (and the imports `use App\Enums\CredentialStorageMode;`, `use App\Enums\VaultProvider;`, `use App\Models\CredentialVaultReference;`):

```php
    public function external(?VaultProvider $provider = null, ?string $resource = null): static
    {
        return $this
            ->state(fn (): array => ['storage_mode' => CredentialStorageMode::External])
            ->afterCreating(function ($credential) use ($provider, $resource): void {
                CredentialVaultReference::factory()->create([
                    'credential_id' => $credential->id,
                    'team_id' => $credential->team_id,
                    ...($provider ? ['provider' => $provider] : []),
                    ...($resource ? ['resource' => $resource] : []),
                ]);
            });
    }
```

Also add `'storage_mode' => CredentialStorageMode::Local,` to `CredentialFactory::definition()` so the default is explicit rather than relying on the column default.

- [ ] **Step 7: Run the test to verify it passes**

Run: `cd apps/platform && php artisan test --filter=CredentialVaultReferenceTest`
Expected: PASS, 5 tests.

- [ ] **Step 8: Commit**

```bash
git add apps/platform/database/migrations/2026_08_02_100100_create_credential_vault_references_table.php \
  apps/platform/app/Models/CredentialVaultReference.php apps/platform/app/Models/Credential.php \
  apps/platform/database/factories apps/platform/tests/Feature/CredentialVaultReferenceTest.php
git commit -m "feat(credentials): add the credential vault reference model"
```

---

## Task 3: External validation rules in `CredentialSchema`

**Files:**
- Modify: `apps/platform/app/Support/Credentials/CredentialSchema.php`
- Test: `apps/platform/tests/Unit/CredentialSchemaExternalRulesTest.php`

**Interfaces:**
- Consumes: `VaultProvider` (Task 1).
- Produces: `CredentialSchema::externalRules(CredentialType $type): array<string, list<mixed>>` — a validation rule set for an external credential. `CredentialSchema::rules()` keeps its current signature and behaviour.

- [ ] **Step 1: Write the failing test**

Create `apps/platform/tests/Unit/CredentialSchemaExternalRulesTest.php`:

```php
<?php

namespace Tests\Unit;

use App\Enums\CredentialType;
use App\Enums\VaultProvider;
use App\Support\Credentials\CredentialSchema;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Validator;
use Tests\TestCase;

class CredentialSchemaExternalRulesTest extends TestCase
{
    public function test_apns_key_keeps_its_non_secret_fields(): void
    {
        $validator = Validator::make([
            'vault' => [
                'provider' => VaultProvider::AwsSecretsManager->value,
                'resource' => 'arn:aws:secretsmanager:eu-central-1:1:secret:apns',
            ],
            'payload' => ['key_id' => 'ABCDEFGHIJ', 'team_id' => 'KLMNOPQRST'],
        ], CredentialSchema::externalRules(CredentialType::ApnsKey));

        $this->assertFalse($validator->fails(), (string) $validator->errors());
    }

    public function test_keystore_rejects_password_fields(): void
    {
        $validator = Validator::make([
            'vault' => [
                'provider' => VaultProvider::HashicorpVault->value,
                'resource' => 'secret/data/android',
            ],
            'payload' => [
                'key_alias' => 'release',
                'store_password' => 'should-not-be-here',
            ],
        ], CredentialSchema::externalRules(CredentialType::AndroidKeystore));

        $this->assertTrue($validator->fails());
        $this->assertArrayHasKey('payload.store_password', $validator->errors()->messages());
    }

    public function test_a_file_is_prohibited(): void
    {
        $validator = Validator::make([
            'vault' => [
                'provider' => VaultProvider::Custom->value,
                'resource' => 'anywhere',
            ],
            'payload' => [],
            'file' => UploadedFile::fake()->create('cert.p12', 4),
        ], CredentialSchema::externalRules(CredentialType::IosCertificate));

        $this->assertTrue($validator->fails());
        $this->assertArrayHasKey('file', $validator->errors()->messages());
    }

    public function test_a_provisioning_profile_needs_only_the_vault_block(): void
    {
        $validator = Validator::make([
            'vault' => [
                'provider' => VaultProvider::FastlaneMatch->value,
                'resource' => 'git@github.com:acme/certs.git#appstore/com.acme.app',
            ],
        ], CredentialSchema::externalRules(CredentialType::ProvisioningProfile));

        $this->assertFalse($validator->fails(), (string) $validator->errors());
    }

    public function test_the_vault_block_is_required_and_validated(): void
    {
        $validator = Validator::make([
            'vault' => ['provider' => 'not-a-provider', 'resource' => ''],
            'payload' => [],
        ], CredentialSchema::externalRules(CredentialType::IosCertificate));

        $this->assertTrue($validator->fails());
        $errors = $validator->errors()->messages();
        $this->assertArrayHasKey('vault.provider', $errors);
        $this->assertArrayHasKey('vault.resource', $errors);
    }

    public function test_nested_metadata_is_rejected(): void
    {
        $validator = Validator::make([
            'vault' => [
                'provider' => VaultProvider::Custom->value,
                'resource' => 'anywhere',
                'metadata' => ['region' => ['nested' => 'value']],
            ],
            'payload' => [],
        ], CredentialSchema::externalRules(CredentialType::IosCertificate));

        $this->assertTrue($validator->fails());
        $this->assertArrayHasKey('vault.metadata.region', $validator->errors()->messages());
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `cd apps/platform && php artisan test --filter=CredentialSchemaExternalRulesTest`
Expected: FAIL — `Call to undefined method …CredentialSchema::externalRules()`.

- [ ] **Step 3: Extract the per-field rules, then add `externalRules()`**

In `app/Support/Credentials/CredentialSchema.php`, add the import `use App\Enums\VaultProvider;`.

Replace the body of the `foreach` inside the existing `rules()` with a call to a new private helper, so the two rule builders cannot drift:

```php
        foreach ($definition['fields'] as $field) {
            $rules['payload.'.$field['name']] = self::fieldRules($field['name']);
        }

        return $rules;
    }

    public static function externalRules(CredentialType $type): array
    {
        $definition = self::definition($type);
        $visibleNames = array_column(
            array_filter(
                $definition['fields'],
                fn (array $field): bool => $field['input'] !== 'password',
            ),
            'name',
        );

        $rules = [
            'file' => ['prohibited'],
            'vault' => ['required', 'array:provider,resource,metadata'],
            'vault.provider' => ['required', Rule::enum(VaultProvider::class)],
            'vault.resource' => ['required', 'string', 'max:2000'],
            'vault.metadata' => ['nullable', 'array'],
            'vault.metadata.*' => ['nullable', 'string', 'max:500'],
            'payload' => $visibleNames
                ? ['required', 'array:'.implode(',', $visibleNames)]
                : ['nullable', 'array', 'max:0'],
        ];

        foreach ($definition['fields'] as $field) {
            // A password silently accepted here would be exactly the leak that
            // external storage exists to prevent, so it is prohibited rather
            // than merely optional.
            $rules['payload.'.$field['name']] = $field['input'] === 'password'
                ? ['prohibited']
                : self::fieldRules($field['name']);
        }

        return $rules;
    }

    private static function fieldRules(string $name): array
    {
        return match ($name) {
            'key_id', 'team_id' => ['required', 'string', 'size:10', 'regex:/^[A-Z0-9]+$/'],
            'issuer_id' => ['required', 'uuid'],
            default => ['required', 'string', 'max:2000'],
        };
    }
```

`vault.metadata.*` uses `string` rather than a scalar union because HTTP form input arrives as strings anyway, and a JSON API client sending a number will be coerced by Laravel's `string` rule only if it is a scalar — an array or object fails, which is the case this rule is guarding.

- [ ] **Step 4: Run the test to verify it passes**

Run: `cd apps/platform && php artisan test --filter=CredentialSchemaExternalRulesTest`
Expected: PASS, 6 tests.

- [ ] **Step 5: Verify the local path is unchanged**

Run: `cd apps/platform && php artisan test --filter="CredentialInputValidationTest|CredentialSchemaTighteningTest|ValidCredentialFileTest"`
Expected: PASS — the `fieldRules()` extraction is behaviour-preserving.

- [ ] **Step 6: Commit**

```bash
git add apps/platform/app/Support/Credentials/CredentialSchema.php \
  apps/platform/tests/Unit/CredentialSchemaExternalRulesTest.php
git commit -m "feat(credentials): add external-storage validation rules to the schema"
```

---

## Task 4: Uniqueness for external credentials

**Files:**
- Modify: `apps/platform/app/Support/Credentials/CredentialUniqueness.php`
- Test: `apps/platform/tests/Feature/CredentialUniquenessTest.php` (append methods)

**Interfaces:**
- Consumes: `Credential->vaultReference()` (Task 2), `VaultProvider` (Task 1).
- Produces: `CredentialUniqueness::findMatchingVaultReference(Team $team, CredentialType $type, VaultProvider $provider, string $resource): ?Credential`

- [ ] **Step 1: Write the failing test**

Append to `apps/platform/tests/Feature/CredentialUniquenessTest.php` (add the imports `use App\Enums\VaultProvider;`, `use App\Support\Credentials\CredentialUniqueness;` if absent):

```php
    public function test_matching_vault_reference_is_found_within_the_team_and_type(): void
    {
        $team = Team::factory()->create();
        $existing = Credential::factory()
            ->external(VaultProvider::AwsSecretsManager, 'arn:aws:secretsmanager:eu:1:secret:ios')
            ->create(['team_id' => $team->id, 'type' => CredentialType::ApnsKey]);

        $match = app(CredentialUniqueness::class)->findMatchingVaultReference(
            $team,
            CredentialType::ApnsKey,
            VaultProvider::AwsSecretsManager,
            'arn:aws:secretsmanager:eu:1:secret:ios',
        );

        $this->assertNotNull($match);
        $this->assertSame($existing->id, $match->id);
    }

    public function test_vault_reference_does_not_match_across_teams_types_or_providers(): void
    {
        $team = Team::factory()->create();
        $other = Team::factory()->create();
        $resource = 'arn:aws:secretsmanager:eu:1:secret:ios';

        Credential::factory()
            ->external(VaultProvider::AwsSecretsManager, $resource)
            ->create(['team_id' => $other->id, 'type' => CredentialType::ApnsKey]);
        Credential::factory()
            ->external(VaultProvider::AwsSecretsManager, $resource)
            ->create(['team_id' => $team->id, 'type' => CredentialType::AppleApiKey]);
        Credential::factory()
            ->external(VaultProvider::HashicorpVault, $resource)
            ->create(['team_id' => $team->id, 'type' => CredentialType::ApnsKey]);

        $this->assertNull(app(CredentialUniqueness::class)->findMatchingVaultReference(
            $team,
            CredentialType::ApnsKey,
            VaultProvider::AwsSecretsManager,
            $resource,
        ));
    }

    public function test_local_credentials_are_never_returned_as_vault_matches(): void
    {
        $team = Team::factory()->create();
        Credential::factory()->create([
            'team_id' => $team->id,
            'type' => CredentialType::ApnsKey,
        ]);

        $this->assertNull(app(CredentialUniqueness::class)->findMatchingVaultReference(
            $team,
            CredentialType::ApnsKey,
            VaultProvider::AwsSecretsManager,
            'arn:aws:secretsmanager:eu:1:secret:ios',
        ));
    }
```

- [ ] **Step 2: Run test to verify it fails**

Run: `cd apps/platform && php artisan test --filter=CredentialUniquenessTest`
Expected: FAIL — `Call to undefined method …findMatchingVaultReference()`.

- [ ] **Step 3: Implement the method**

In `app/Support/Credentials/CredentialUniqueness.php` add the imports `use App\Enums\CredentialStorageMode;`, `use App\Enums\VaultProvider;`, `use Illuminate\Database\Eloquent\Builder;` and the method:

```php
    public function findMatchingVaultReference(
        Team $team,
        CredentialType $type,
        VaultProvider $provider,
        string $resource,
    ): ?Credential {
        return $team->credentials()
            ->where('type', $type->value)
            ->where('storage_mode', CredentialStorageMode::External->value)
            ->whereHas('vaultReference', fn (Builder $query) => $query
                ->where('provider', $provider->value)
                ->where('resource', $resource))
            ->oldest()
            ->first();
    }
```

There is deliberately no lazy-backfill loop here, unlike `findMatching()`: a vault reference always carries its provider and resource, so there is nothing to recompute.

- [ ] **Step 4: Run the test to verify it passes**

Run: `cd apps/platform && php artisan test --filter=CredentialUniquenessTest`
Expected: PASS — the three new tests plus every pre-existing one.

- [ ] **Step 5: Commit**

```bash
git add apps/platform/app/Support/Credentials/CredentialUniqueness.php \
  apps/platform/tests/Feature/CredentialUniquenessTest.php
git commit -m "feat(credentials): detect duplicate vault references"
```

---

## Task 5: Web create/update — the external branch

**Files:**
- Create: `apps/platform/app/Actions/Credentials/SyncVaultReference.php`
- Create: `apps/platform/app/Support/Credentials/CredentialRequestRules.php`
- Modify: `apps/platform/app/Http/Controllers/CredentialController.php` (`store`, `update`)
- Test: `apps/platform/tests/Feature/CredentialExternalStorageTest.php`

**`CredentialRequestRules` exists so Task 6 does not repeat this task's branching.** The web and API controllers each assemble their own base rules — their `app_identifier_id` existence rule differs and only the web one accepts `check_uniqueness` — but the storage-mode gate and the file-versus-vault choice are identical in both, and drifting them apart is how a rule gets tightened on one surface and not the other.

**Interfaces:**
- Consumes: `StorageModePolicy` (Task 1), `CredentialVaultReference` (Task 2), `CredentialSchema::externalRules()` (Task 3), `CredentialUniqueness::findMatchingVaultReference()` (Task 4).
- Produces, where `$vault` is `array{provider: string, resource: string, metadata?: array<string, string|null>|null}`:
  - `SyncVaultReference::create(Credential $credential, array $vault, VaultMetadataSource $source): CredentialVaultReference`
  - `SyncVaultReference::update(CredentialVaultReference $reference, array $vault, VaultMetadataSource $source): CredentialVaultReference`
  - `CredentialRequestRules::resolveMode(Team $team, Request $request): CredentialStorageMode`
  - `CredentialRequestRules::forCreate(Team $team, Request $request, CredentialStorageMode $mode, ?CredentialType $type): array<string, mixed>`
  - `CredentialRequestRules::forUpdate(Credential $credential, Request $request, ?CredentialType $type, bool $partial = false): array<string, mixed>` — `$partial` is for the API's partial update: when true, payload/file/vault rules are contributed only if the request supplies one of them
  - `CredentialRequestRules::assertModeIsUnchanged(Credential $credential, Request $request): void`

- [ ] **Step 1: Write the failing test**

Create `apps/platform/tests/Feature/CredentialExternalStorageTest.php`:

```php
<?php

namespace Tests\Feature;

use App\Enums\CredentialStorageMode;
use App\Enums\CredentialType;
use App\Enums\Platform;
use App\Enums\VaultMetadataSource;
use App\Enums\VaultProvider;
use App\Models\Credential;
use App\Models\MobileApp;
use App\Models\Team;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Tests\TestCase;

class CredentialExternalStorageTest extends TestCase
{
    use RefreshDatabase;

    public function test_manager_creates_an_external_credential_with_a_vault_reference(): void
    {
        [$owner, $team, $app, $identifier] = $this->workspace();

        $this->actingAs($owner)
            ->post(route('accounts.apps.credentials.store', [$team, $app]), [
                'app_identifier_id' => $identifier->id,
                'name' => 'APNs production',
                'type' => CredentialType::ApnsKey->value,
                'storage_mode' => CredentialStorageMode::External->value,
                'vault' => [
                    'provider' => VaultProvider::AwsSecretsManager->value,
                    'resource' => 'arn:aws:secretsmanager:eu-central-1:1:secret:apns',
                    'metadata' => ['region' => 'eu-central-1'],
                ],
                'payload' => ['key_id' => 'ABCDEFGHIJ', 'team_id' => 'KLMNOPQRST'],
                'expires_at' => now()->addYear()->toDateString(),
            ])
            ->assertRedirect();

        $credential = $team->credentials()->firstOrFail();
        $this->assertSame(CredentialStorageMode::External, $credential->storage_mode);
        $this->assertSame(['key_id' => 'ABCDEFGHIJ', 'team_id' => 'KLMNOPQRST'], $credential->payload);
        $this->assertNull($credential->getFirstMedia('file'));

        $reference = $credential->vaultReference;
        $this->assertSame(VaultProvider::AwsSecretsManager, $reference->provider);
        $this->assertSame('arn:aws:secretsmanager:eu-central-1:1:secret:apns', $reference->resource);
        $this->assertSame(['region' => 'eu-central-1'], $reference->metadata);
        $this->assertSame(VaultMetadataSource::User, $reference->metadata_source);
        $this->assertSame(1, $reference->metadata_version);
        $this->assertTrue($identifier->credentials()->whereKey($credential->id)->exists());
    }

    public function test_external_creation_rejects_a_file_and_a_password_field(): void
    {
        [$owner, $team, $app, $identifier] = $this->workspace();

        $this->actingAs($owner)
            ->post(route('accounts.apps.credentials.store', [$team, $app]), [
                'app_identifier_id' => $identifier->id,
                'name' => 'Distribution certificate',
                'type' => CredentialType::IosCertificate->value,
                'storage_mode' => CredentialStorageMode::External->value,
                'profile_type' => 'app_store',
                'vault' => [
                    'provider' => VaultProvider::HashicorpVault->value,
                    'resource' => 'secret/data/ios',
                ],
                'payload' => ['password' => 'leaked'],
                'file' => UploadedFile::fake()->create('cert.p12', 4),
            ])
            ->assertSessionHasErrors(['file', 'payload.password']);

        $this->assertSame(0, $team->credentials()->count());
    }

    public function test_a_mode_the_team_does_not_permit_is_rejected(): void
    {
        [$owner, $team, $app, $identifier] = $this->workspace();
        $team->update(['allowed_credential_storage_modes' => ['local']]);

        $this->actingAs($owner)
            ->post(route('accounts.apps.credentials.store', [$team, $app]), [
                'app_identifier_id' => $identifier->id,
                'name' => 'APNs production',
                'type' => CredentialType::ApnsKey->value,
                'storage_mode' => CredentialStorageMode::External->value,
                'vault' => [
                    'provider' => VaultProvider::Custom->value,
                    'resource' => 'somewhere',
                ],
                'payload' => ['key_id' => 'ABCDEFGHIJ', 'team_id' => 'KLMNOPQRST'],
            ])
            ->assertSessionHasErrors('storage_mode');
    }

    public function test_a_duplicate_vault_reference_is_reused_instead_of_created(): void
    {
        [$owner, $team, $app, $identifier] = $this->workspace();
        $team->update(['check_credential_uniqueness_by_default' => true]);
        $existing = Credential::factory()
            ->external(VaultProvider::AwsSecretsManager, 'arn:aws:secretsmanager:eu:1:secret:apns')
            ->create(['team_id' => $team->id, 'type' => CredentialType::ApnsKey]);

        $this->actingAs($owner)
            ->post(route('accounts.apps.credentials.store', [$team, $app]), [
                'app_identifier_id' => $identifier->id,
                'name' => 'APNs production again',
                'type' => CredentialType::ApnsKey->value,
                'storage_mode' => CredentialStorageMode::External->value,
                'vault' => [
                    'provider' => VaultProvider::AwsSecretsManager->value,
                    'resource' => 'arn:aws:secretsmanager:eu:1:secret:apns',
                ],
                'payload' => ['key_id' => 'ABCDEFGHIJ', 'team_id' => 'KLMNOPQRST'],
            ])
            ->assertRedirect();

        $this->assertSame(1, $team->credentials()->count());
        $this->assertTrue($identifier->credentials()->whereKey($existing->id)->exists());
    }

    public function test_storage_mode_cannot_be_changed_on_update(): void
    {
        [$owner, $team] = $this->workspace();
        $credential = Credential::factory()
            ->external(VaultProvider::Custom, 'somewhere')
            ->create(['team_id' => $team->id, 'type' => CredentialType::ApnsKey]);

        $this->actingAs($owner)
            ->patch(route('accounts.credentials.update', [$team, $credential]), [
                'name' => 'Renamed',
                'type' => CredentialType::ApnsKey->value,
                'storage_mode' => CredentialStorageMode::Local->value,
                'vault' => ['provider' => VaultProvider::Custom->value, 'resource' => 'somewhere'],
                'payload' => ['key_id' => 'ABCDEFGHIJ', 'team_id' => 'KLMNOPQRST'],
            ])
            ->assertSessionHasErrors('storage_mode');

        $this->assertSame(CredentialStorageMode::External, $credential->fresh()->storage_mode);
    }

    public function test_updating_an_external_credential_rewrites_the_reference(): void
    {
        [$owner, $team] = $this->workspace();
        $credential = Credential::factory()
            ->external(VaultProvider::Custom, 'old-address')
            ->create(['team_id' => $team->id, 'type' => CredentialType::ApnsKey]);

        $this->actingAs($owner)
            ->patch(route('accounts.credentials.update', [$team, $credential]), [
                'name' => 'APNs production',
                'type' => CredentialType::ApnsKey->value,
                'vault' => [
                    'provider' => VaultProvider::GcpSecretManager->value,
                    'resource' => 'projects/p/secrets/apns/versions/latest',
                    'metadata' => ['project' => 'p'],
                ],
                'payload' => ['key_id' => 'ABCDEFGHIJ', 'team_id' => 'KLMNOPQRST'],
            ])
            ->assertRedirect();

        $reference = $credential->fresh()->vaultReference;
        $this->assertSame(VaultProvider::GcpSecretManager, $reference->provider);
        $this->assertSame('projects/p/secrets/apns/versions/latest', $reference->resource);
        $this->assertSame(2, $reference->metadata_version);
        $this->assertSame(VaultMetadataSource::User, $reference->metadata_source);
    }

    /** @return array{0: User, 1: Team, 2: MobileApp, 3: \App\Models\AppIdentifier} */
    private function workspace(): array
    {
        $owner = User::factory()->create();
        $team = Team::factory()->create(['owner_id' => $owner->id]);
        $team->addUser($owner, 'owner');
        $owner->update(['current_team_id' => $team->id]);
        $app = MobileApp::factory()->create(['team_id' => $team->id]);
        $identifier = $app->appIdentifiers()->create([
            'platform' => Platform::Ios,
            'identifier' => 'com.example.atlas',
            'name' => 'Atlas iOS',
        ]);

        return [$owner->fresh(), $team->fresh(), $app, $identifier];
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `cd apps/platform && php artisan test --filter=CredentialExternalStorageTest`
Expected: FAIL — the first test errors because `file` is still required by `CredentialSchema::rules()`.

- [ ] **Step 3: Create the `SyncVaultReference` action**

`app/Actions/Credentials/SyncVaultReference.php`:

```php
<?php

namespace App\Actions\Credentials;

use App\Enums\CredentialVerificationStatus;
use App\Enums\VaultMetadataSource;
use App\Enums\VaultProvider;
use App\Models\Credential;
use App\Models\CredentialVaultReference;

class SyncVaultReference
{
    /**
     * @param  array{provider: string, resource: string, metadata?: array<string, string|null>|null}  $vault
     */
    public function create(
        Credential $credential,
        array $vault,
        VaultMetadataSource $source,
    ): CredentialVaultReference {
        return CredentialVaultReference::create([
            'credential_id' => $credential->id,
            'team_id' => $credential->team_id,
            'provider' => VaultProvider::from($vault['provider']),
            'resource' => $vault['resource'],
            'metadata' => $vault['metadata'] ?? null,
            'metadata_version' => 1,
            'metadata_source' => $source,
            'verification_status' => CredentialVerificationStatus::Unverified,
        ]);
    }

    /**
     * Rewrites the address and the storage metadata. Verification state is
     * deliberately preserved: a pipeline's report about this credential is
     * still the last thing we actually know, and clearing it on a rename
     * would erase real information.
     *
     * @param  array{provider: string, resource: string, metadata?: array<string, string|null>|null}  $vault
     */
    public function update(
        CredentialVaultReference $reference,
        array $vault,
        VaultMetadataSource $source,
    ): CredentialVaultReference {
        $metadata = $vault['metadata'] ?? null;
        $metadataChanged = $metadata !== $reference->metadata;

        $reference->update([
            'provider' => VaultProvider::from($vault['provider']),
            'resource' => $vault['resource'],
            'metadata' => $metadata,
            'metadata_version' => $metadataChanged
                ? $reference->metadata_version + 1
                : $reference->metadata_version,
            'metadata_source' => $metadataChanged ? $source : $reference->metadata_source,
        ]);

        return $reference;
    }
}
```

Note the `metadata_version` guard: a rename that leaves metadata untouched must not inflate the counter, otherwise the number stops meaning anything.

- [ ] **Step 4: Create the shared rules builder, then branch `CredentialController::store`**

`app/Support/Credentials/CredentialRequestRules.php`:

```php
<?php

namespace App\Support\Credentials;

use App\Enums\CredentialStorageMode;
use App\Enums\CredentialType;
use App\Models\Credential;
use App\Models\Team;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * The storage-mode half of credential request validation, shared by the web
 * and API controllers. Each controller still assembles its own base rules —
 * their identifier and uniqueness rules genuinely differ — but the gate on
 * the mode and the choice between file rules and vault rules lives here, so
 * the two surfaces cannot be tightened independently.
 */
class CredentialRequestRules
{
    public function __construct(
        private readonly StorageModePolicy $storageModes,
    ) {}

    public function resolveMode(Team $team, Request $request): CredentialStorageMode
    {
        return CredentialStorageMode::tryFrom((string) $request->input('storage_mode'))
            ?? $this->storageModes->defaultFor($team);
    }

    /** @return array<string, mixed> */
    public function forCreate(
        Team $team,
        Request $request,
        CredentialStorageMode $mode,
        ?CredentialType $type,
    ): array {
        $rules = [
            'storage_mode' => ['sometimes', Rule::in($this->storageModes->allowedValuesFor($team))],
        ];

        if ($type) {
            $rules = [
                ...$rules,
                ...($mode === CredentialStorageMode::External
                    ? CredentialSchema::externalRules($type)
                    : CredentialSchema::rules($type, true, (array) $request->input('payload', []))),
            ];
        }

        if ($mode === CredentialStorageMode::Local) {
            $rules['vault'] = ['prohibited'];
        }

        return $rules;
    }

    /** @return array<string, mixed> */
    public function forUpdate(Credential $credential, Request $request, ?CredentialType $type): array
    {
        if (! $type) {
            return [];
        }

        return $credential->isExternal()
            ? CredentialSchema::externalRules($type)
            : CredentialSchema::rules(
                $type,
                $type !== $credential->type || ! $credential->getFirstMedia('file'),
                (array) $request->input('payload', []),
            );
    }

    /** @throws ValidationException */
    public function assertModeIsUnchanged(Credential $credential, Request $request): void
    {
        $requested = CredentialStorageMode::tryFrom((string) $request->input('storage_mode'));

        if ($requested !== null && $requested !== $credential->storage_mode) {
            throw ValidationException::withMessages([
                'storage_mode' => 'Storage mode is fixed when a credential is created. Create a new credential instead.',
            ]);
        }
    }
}
```

Now in `app/Http/Controllers/CredentialController.php` add the imports:

```php
use App\Actions\Credentials\SyncVaultReference;
use App\Enums\CredentialStorageMode;
use App\Enums\VaultMetadataSource;
use App\Enums\VaultProvider;
use App\Support\Credentials\CredentialRequestRules;
```

Add both collaborators to the constructor:

```php
    public function __construct(
        private readonly WebhookEventRecorder $webhookEvents,
        private readonly CredentialUniqueness $credentialUniqueness,
        private readonly ExpoAutoSyncDispatcher $expoAutoSync,
        private readonly CredentialAccessAuditRecorder $credentialAccessAudits,
        private readonly CredentialRequestRules $credentialRules,
        private readonly SyncVaultReference $vaultReferences,
    ) {}
```

In `store()`, replace the rule assembly (currently the block that begins `$type = CredentialType::tryFrom(...)` and ends with `$data = $request->validate($rules);`) with:

```php
        $type = CredentialType::tryFrom((string) $request->input('type'));
        $mode = $this->credentialRules->resolveMode($team, $request);

        $rules = [
            'app_identifier_id' => [
                'required',
                Rule::exists('app_identifiers', 'id')->where('mobile_app_id', $mobileApp->id),
            ],
            'name' => ['required', 'string', 'max:255'],
            'type' => ['required', Rule::enum(CredentialType::class)],
            'expires_at' => ['nullable', 'date'],
            'check_uniqueness' => ['sometimes', 'boolean'],
            ...$this->credentialRules->forCreate($team, $request, $mode, $type),
        ];

        if ($type) {
            $rules = [...$rules, ...CredentialSchema::attachmentRules($type)];
        }

        $data = $request->validate($rules);
        $data['storage_mode'] = $mode->value;
```

Because `forCreate()` validates `storage_mode` with `Rule::in(allowedValuesFor($team))`, a mode the team may not use produces a `storage_mode` error, which is what the third test asserts. When the request omits `storage_mode` entirely the `sometimes` rule skips it and the team default applies.

Replace the fingerprint line with a mode-aware version:

```php
        $fingerprint = $mode === CredentialStorageMode::External
            ? null
            : $this->credentialUniqueness->fingerprintUpload(
                $type,
                $data['payload'] ?? [],
                $request->file('file'),
            );
```

Inside the transaction closure, add `$mode` and `$this->vaultReferences` to the `use (...)` list, then replace the duplicate lookup and the creation block with:

```php
            $credential = $checkUniqueness
                ? ($mode === CredentialStorageMode::External
                    ? $this->credentialUniqueness->findMatchingVaultReference(
                        $team,
                        $type,
                        VaultProvider::from($data['vault']['provider']),
                        $data['vault']['resource'],
                    )
                    : $this->credentialUniqueness->findMatching($team, $type, $fingerprint))
                : null;
            $reused = $credential !== null;

            if (! $credential) {
                $credential = $mobileApp->team->credentials()->create([
                    ...Arr::only($data, ['name', 'type', 'storage_mode', 'expires_at']),
                    'payload' => $data['payload'] ?? null,
                    'fingerprint' => $fingerprint,
                ]);

                if ($mode === CredentialStorageMode::External) {
                    $this->vaultReferences->create(
                        $credential,
                        $data['vault'],
                        VaultMetadataSource::User,
                    );
                }
            }
```

Finally, guard the file write so an external credential never touches MediaLibrary:

```php
            if (! $reused && $mode === CredentialStorageMode::Local && $request->hasFile('file')) {
                CredentialFileStorage::storeUploaded($credential, $request->file('file'));
            }
```

- [ ] **Step 5: Branch `CredentialController::update`**

At the top of `update()`, before anything else:

```php
        $this->credentialRules->assertModeIsUnchanged($credential, $request);
```

Guard the password-carry-over block so it only runs for local credentials — an external credential has no stored passwords to carry over:

```php
        if ($type === $credential->type && ! $credential->isExternal()) {
            // …existing password carry-over loop, unchanged…
        }
```

Replace the rule assembly with:

```php
        $rules = [
            'name' => ['required', 'string', 'max:255'],
            'type' => ['required', Rule::enum(CredentialType::class)],
            'expires_at' => ['nullable', 'date'],
            ...$this->credentialRules->forUpdate($credential, $request, $type),
        ];
```

Inside the transaction, rewrite the reference and skip both the file write and the fingerprint refresh for external credentials:

```php
            if ($credential->isExternal()) {
                $this->vaultReferences->update(
                    $credential->vaultReference,
                    $data['vault'],
                    VaultMetadataSource::User,
                );
            } else {
                if ($request->hasFile('file')) {
                    CredentialFileStorage::storeUploaded($credential, $request->file('file'));
                }

                $credential->forceFill([
                    'fingerprint' => $this->credentialUniqueness->fingerprintCredential($credential),
                ])->saveQuietly();
            }
```

- [ ] **Step 6: Run the test to verify it passes**

Run: `cd apps/platform && php artisan test --filter=CredentialExternalStorageTest`
Expected: PASS, 6 tests.

- [ ] **Step 7: Run every credential feature test for regressions**

Run: `cd apps/platform && php artisan test --filter=Credential`
Expected: PASS. If `CredentialUpdateTest` or `CredentialReuseTest` fails, the local branch has drifted — fix the branch, not the test.

- [ ] **Step 8: Commit**

```bash
git add apps/platform/app/Actions/Credentials/SyncVaultReference.php \
  apps/platform/app/Support/Credentials/CredentialRequestRules.php \
  apps/platform/app/Http/Controllers/CredentialController.php \
  apps/platform/tests/Feature/CredentialExternalStorageTest.php
git commit -m "feat(credentials): create and update external credentials from the dashboard"
```

---

## Task 6: API create/update and the `vault` block in `CredentialResource`

**Files:**
- Modify: `apps/platform/app/Http/Controllers/Api/V1/CredentialController.php` (`store`, `update`, `relationsFor`)
- Modify: `apps/platform/app/Http/Resources/Api/V1/CredentialResource.php`
- Test: `apps/platform/tests/Feature/Api/V1/CredentialExternalStorageApiTest.php`

**Interfaces:**
- Consumes: everything from Tasks 1–5.
- Produces: the `storage_mode` and `vault` keys in the credential API representation, exactly as specified in the design doc.

- [ ] **Step 1: Write the failing test**

Create `apps/platform/tests/Feature/Api/V1/CredentialExternalStorageApiTest.php`:

```php
<?php

namespace Tests\Feature\Api\V1;

use App\Enums\ApiKeyScope;
use App\Enums\CredentialStorageMode;
use App\Enums\CredentialType;
use App\Enums\Platform;
use App\Enums\VaultProvider;
use App\Models\AppIdentifier;
use App\Models\MobileApp;
use App\Models\Team;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Tests\TestCase;

class CredentialExternalStorageApiTest extends TestCase
{
    use RefreshDatabase;

    public function test_external_credential_is_created_and_exposed_with_a_vault_block(): void
    {
        [$user, $team, $app, $identifier] = $this->workspace();
        $this->authenticate($user, $team, [
            ApiKeyScope::CredentialsRead,
            ApiKeyScope::CredentialsWrite,
        ]);

        $url = "/api/v1/{$team->slug}/apps/{$app->slug}/credentials";

        $created = $this->postJson($url, [
            'app_identifier_id' => $identifier->id,
            'type' => CredentialType::ApnsKey->value,
            'name' => 'APNs production',
            'storage_mode' => CredentialStorageMode::External->value,
            'vault' => [
                'provider' => VaultProvider::AwsSecretsManager->value,
                'resource' => 'arn:aws:secretsmanager:eu-central-1:1:secret:apns',
                'metadata' => ['region' => 'eu-central-1'],
            ],
            'payload' => ['key_id' => 'ABCDEFGHIJ', 'team_id' => 'KLMNOPQRST'],
        ])
            ->assertCreated()
            ->assertJsonPath('data.storage_mode', 'external')
            ->assertJsonPath('data.file', null)
            ->assertJsonPath('data.vault.provider', 'aws_secrets_manager')
            ->assertJsonPath('data.vault.resource', 'arn:aws:secretsmanager:eu-central-1:1:secret:apns')
            ->assertJsonPath('data.vault.metadata.region', 'eu-central-1')
            ->assertJsonPath('data.vault.metadata_version', 1)
            ->assertJsonPath('data.vault.metadata_source', 'user')
            ->assertJsonPath('data.vault.verification.status', 'unverified')
            ->assertJsonPath('data.vault.verification.verified_at', null);

        $id = $created->json('data.id');

        $this->getJson("{$url}/{$id}")
            ->assertOk()
            ->assertJsonPath('data.vault.resource', 'arn:aws:secretsmanager:eu-central-1:1:secret:apns');
    }

    public function test_local_credential_reports_a_null_vault_block(): void
    {
        [$user, $team, $app, $identifier] = $this->workspace();
        $this->authenticate($user, $team, [
            ApiKeyScope::CredentialsRead,
            ApiKeyScope::CredentialsWrite,
        ]);

        $this->post("/api/v1/{$team->slug}/apps/{$app->slug}/credentials", [
            'app_identifier_id' => $identifier->id,
            'type' => CredentialType::ApnsKey->value,
            'name' => 'APNs production',
            'payload' => ['key_id' => 'ABCDEFGHIJ', 'team_id' => 'KLMNOPQRST'],
            'file' => UploadedFile::fake()->createWithContent('key.p8', 'private-key'),
        ], ['Accept' => 'application/json'])
            ->assertCreated()
            ->assertJsonPath('data.storage_mode', 'local')
            ->assertJsonPath('data.vault', null)
            ->assertJsonPath('data.file.name', 'key.p8');
    }

    public function test_external_creation_rejects_a_file(): void
    {
        [$user, $team, $app, $identifier] = $this->workspace();
        $this->authenticate($user, $team, [ApiKeyScope::CredentialsWrite]);

        $this->post("/api/v1/{$team->slug}/apps/{$app->slug}/credentials", [
            'app_identifier_id' => $identifier->id,
            'type' => CredentialType::ApnsKey->value,
            'name' => 'APNs production',
            'storage_mode' => CredentialStorageMode::External->value,
            'vault' => [
                'provider' => VaultProvider::Custom->value,
                'resource' => 'somewhere',
            ],
            'payload' => ['key_id' => 'ABCDEFGHIJ', 'team_id' => 'KLMNOPQRST'],
            'file' => UploadedFile::fake()->createWithContent('key.p8', 'private-key'),
        ], ['Accept' => 'application/json'])
            ->assertStatus(422)
            ->assertJsonValidationErrors('file');
    }

    public function test_downloading_an_external_credential_file_is_not_found(): void
    {
        [$user, $team, $app, $identifier] = $this->workspace();
        $credential = \App\Models\Credential::factory()
            ->external(VaultProvider::Custom, 'somewhere')
            ->create(['team_id' => $team->id, 'type' => CredentialType::ApnsKey]);
        $identifier->credentials()->attach($credential);
        $this->authenticate($user, $team, [ApiKeyScope::CredentialsReveal]);

        $this->getJson("/api/v1/{$team->slug}/apps/{$app->slug}/credentials/{$credential->id}/file")
            ->assertNotFound();
    }

    /** @return array{0: User, 1: Team, 2: MobileApp, 3: AppIdentifier} */
    private function workspace(): array
    {
        $user = User::factory()->create();
        $team = Team::factory()->create(['owner_id' => $user->id]);
        $team->users()->attach($user, ['role' => 'owner']);
        $app = MobileApp::factory()->create(['team_id' => $team->id]);
        $identifier = AppIdentifier::factory()->create([
            'mobile_app_id' => $app->id,
            'platform' => Platform::Ios,
        ]);

        return [$user, $team, $app, $identifier];
    }

    /** @param list<ApiKeyScope> $scopes */
    private function authenticate(User $user, Team $team, array $scopes): void
    {
        $token = $user->createTeamApiKey(
            $team,
            'Runner',
            array_map(fn (ApiKeyScope $scope): string => $scope->value, $scopes),
        )->plainTextToken;
        $this->app['auth']->guard('sanctum')->forgetUser();
        $this->withToken($token);
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `cd apps/platform && php artisan test --filter=CredentialExternalStorageApiTest`
Expected: FAIL — 422, because `file` is still required.

- [ ] **Step 3: Extend `CredentialResource`**

In `app/Http/Resources/Api/V1/CredentialResource.php`, load the reference and emit the block. Add `use App\Models\CredentialVaultReference;` and insert into the returned array, right after `'file' => …`:

```php
            'storage_mode' => $this->storage_mode->value,
            'vault' => $this->resource->relationLoaded('vaultReference')
                ? $this->vaultBlock($this->resource->vaultReference)
                : $this->vaultBlock($this->resource->vaultReference()->first()),
```

and add the private method to the class:

```php
    /** @return array<string, mixed>|null */
    private function vaultBlock(?CredentialVaultReference $reference): ?array
    {
        if (! $reference) {
            return null;
        }

        return [
            'provider' => $reference->provider->value,
            'resource' => $reference->resource,
            'metadata' => $reference->metadata,
            'metadata_version' => $reference->metadata_version,
            'metadata_source' => $reference->metadata_source->value,
            'verification' => [
                'status' => $reference->verification_status->value,
                'verified_at' => $reference->verified_at,
                'error' => $reference->verification_error,
            ],
        ];
    }
```

To keep `index` off an N+1, add `'vaultReference'` to the array returned by `CredentialController::relationsFor()`.

**The API block deliberately omits `provider_label`, which the two Inertia payloads carry.** A machine consumer gets `provider` and maps the enum itself; putting our English label into the API contract would freeze a UI string into it. The Svelte pages get the label so the frontend does not keep a second copy of the enum. The two Inertia payloads — `CredentialPresenter` (Task 8) and `CredentialVaultController::presentCredential()` (Task 9) — must stay identical to each other.

- [ ] **Step 4: Branch `Api\V1\CredentialController::store`**

Add the same imports and constructor collaborators as in Task 5 — `CredentialRequestRules`, `SyncVaultReference`, `CredentialStorageMode`, `VaultMetadataSource`, `VaultProvider`. **Do not re-derive the mode or re-assemble the storage rules here**; Task 5 created `CredentialRequestRules` precisely so this controller consumes it.

Replace the rule assembly in `store()`:

```php
        $type = CredentialType::tryFrom((string) $request->input('type'));
        $mode = $this->credentialRules->resolveMode($team, $request);

        $rules = [
            'app_identifier_id' => [
                'required',
                Rule::exists('app_identifiers', 'id')->where('mobile_app_id', $mobileApp->id),
            ],
            'name' => ['required', 'string', 'max:255'],
            'type' => ['required', Rule::enum(CredentialType::class)],
            'expires_at' => ['nullable', 'date'],
            ...$this->credentialRules->forCreate($team, $request, $mode, $type),
        ];

        if ($type) {
            $rules = [...$rules, ...CredentialSchema::attachmentRules($type)];
        }

        $data = $request->validate($rules);
        $data['storage_mode'] = $mode->value;
```

The one difference from the web controller is the absence of `check_uniqueness`: the API has never accepted it.

Inside the transaction closure add `$mode` to `use (...)` and replace the create + file lines:

```php
            $credential = $team->credentials()->create([
                ...Arr::only($data, ['name', 'type', 'storage_mode', 'expires_at']),
                'payload' => $data['payload'] ?? null,
            ]);
```

```php
            if ($mode === CredentialStorageMode::External) {
                $this->vaultReferences->create($credential, $data['vault'], VaultMetadataSource::User);
            } else {
                CredentialFileStorage::storeUploaded($credential, $request->file('file'));
                $this->refreshFingerprint($credential);
            }
```

- [ ] **Step 5: Branch `Api\V1\CredentialController::update`**

**The API's update is a *partial* update and must stay one.** It currently uses `'name' => ['sometimes', …]`, `'type' => ['prohibited']`, and applies payload/file rules only `if ($request->has('payload'))`. `PlatformApiTest::test_credentials_can_be_managed_with_encrypted_payload_and_file` sends a `patchJson` carrying only `name` and `expires_at` and expects 200. **Do not replace that rule array with the web controller's** — merge into it.

Add the mode lock at the top:

```php
        $this->credentialRules->assertModeIsUnchanged($credential, $request);
```

Then merge the storage rules into the existing array, passing `partial: true` so payload, file and vault rules are contributed only when the request actually supplies one of them:

```php
            ...$this->credentialRules->forUpdate($credential, $request, $type, partial: true),
```

Leave `'name' => ['sometimes', …]` and `'type' => ['prohibited']` exactly as they are.

Inside the transaction, for an external credential call `$this->vaultReferences->update($credential->vaultReference, $data['vault'], VaultMetadataSource::User)` — but only when `$request->has('vault')`, since a partial update may not carry one — instead of writing a file and refreshing the fingerprint.

If this controller has a password carry-over block like the web one, guard it with `! $credential->isExternal()` too.

- [ ] **Step 5b: Close the file-endpoint hole for external credentials**

Task 5 found and fixed this on the web controller; the API has the same two holes. `upload()` and `destroyFile()` have no storage-mode gate, so a client with `credentials:write` can post a `.p8` to an external credential — persisting into MediaLibrary the exact material the mode exists to keep out of the platform, and stamping a fingerprint that makes `findMatching()` start matching it.

Add as the first statement of **both** `Api\V1\CredentialController::upload()` and `::destroyFile()`:

```php
        abort_if($credential->isExternal(), 404);
```

404, not 403: for an external credential the file sub-resource does not exist, which is the answer `download()` already gives.

Add a test to `CredentialExternalStorageApiTest`:

```php
    public function test_uploading_a_file_to_an_external_credential_is_not_found(): void
    {
        [$user, $team, $app, $identifier] = $this->workspace();
        $credential = \App\Models\Credential::factory()
            ->external(VaultProvider::Custom, 'somewhere')
            ->create(['team_id' => $team->id, 'type' => CredentialType::ApnsKey]);
        $identifier->credentials()->attach($credential);
        $this->authenticate($user, $team, [ApiKeyScope::CredentialsWrite]);

        $url = "/api/v1/{$team->slug}/apps/{$app->slug}/credentials/{$credential->id}/file";

        $this->post($url, [
            'file' => UploadedFile::fake()->createWithContent('key.p8', 'private-key'),
        ], ['Accept' => 'application/json'])->assertNotFound();

        $this->deleteJson($url)->assertNotFound();
        $this->assertNull($credential->fresh()->getFirstMedia('file'));
    }
```

- [ ] **Step 6: Run the test to verify it passes**

Run: `cd apps/platform && php artisan test --filter=CredentialExternalStorageApiTest`
Expected: PASS, 4 tests.

- [ ] **Step 7: Run the API suite for regressions**

Run: `cd apps/platform && php artisan test --filter="PlatformApiTest|ExistingRoutesAclTest|ApiKeyScopeTest"`
Expected: PASS.

- [ ] **Step 8: Commit**

```bash
git add apps/platform/app/Http/Controllers/Api/V1/CredentialController.php \
  apps/platform/app/Http/Resources/Api/V1/CredentialResource.php \
  apps/platform/tests/Feature/Api/V1/CredentialExternalStorageApiTest.php
git commit -m "feat(api): create external credentials and expose the vault block"
```

---

## Task 7: The verification endpoint

**Files:**
- Create: `apps/platform/app/Actions/Credentials/RecordVaultVerification.php`
- Create: `apps/platform/app/Http/Controllers/Api/V1/CredentialVerificationController.php`
- Modify: `apps/platform/app/Enums/WebhookEventType.php`
- Modify: `apps/platform/app/Enums/ApiKeyScope.php`
- Modify: `apps/platform/app/Integrations/Webhooks/WebhookPayload.php`
- Modify: `apps/platform/routes/api.php`
- Test: `apps/platform/tests/Feature/Api/V1/CredentialVerificationApiTest.php`

**Interfaces:**
- Consumes: `CredentialVaultReference`, `CredentialVerificationStatus`, `VaultMetadataSource`.
- Produces:
  - `ApiKeyScope::CredentialsVerify = 'credentials:verify'`
  - `WebhookEventType::CredentialVerificationFailed = 'credential.verification_failed'`
  - `WebhookEventType->isCredentialEvent(): bool` — Task 10 depends on this
  - `RecordVaultVerification::__invoke(Team $team, Credential $credential, array $data): CredentialVaultReference`
  - route name `api.v1.apps.credentials.verification.store`

- [ ] **Step 1: Write the failing test**

Create `apps/platform/tests/Feature/Api/V1/CredentialVerificationApiTest.php`:

```php
<?php

namespace Tests\Feature\Api\V1;

use App\Enums\ApiKeyScope;
use App\Enums\CredentialType;
use App\Enums\CredentialVerificationStatus;
use App\Enums\Platform;
use App\Enums\VaultMetadataSource;
use App\Enums\VaultProvider;
use App\Enums\WebhookEventType;
use App\Models\AppIdentifier;
use App\Models\Credential;
use App\Models\MobileApp;
use App\Models\Team;
use App\Models\User;
use App\Models\WebhookEvent;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CredentialVerificationApiTest extends TestCase
{
    use RefreshDatabase;

    public function test_pipeline_reports_a_successful_verification(): void
    {
        [$user, $team, $app, $credential] = $this->externalCredential();
        $this->authenticate($user, $team, [ApiKeyScope::CredentialsVerify]);

        $this->postJson($this->url($team, $app, $credential), [
            'status' => 'verified',
            'expires_at' => '2027-03-01T00:00:00Z',
            'metadata' => ['version_id' => 'a1b2c3'],
        ])
            ->assertOk()
            ->assertJsonPath('data.verification.status', 'verified')
            ->assertJsonPath('data.metadata.version_id', 'a1b2c3')
            ->assertJsonPath('data.metadata_version', 2)
            ->assertJsonPath('data.metadata_source', 'pipeline');

        $reference = $credential->fresh()->vaultReference;
        $this->assertSame(CredentialVerificationStatus::Verified, $reference->verification_status);
        $this->assertNotNull($reference->verified_at);
        $this->assertNull($reference->verification_error);
        $this->assertSame(VaultMetadataSource::Pipeline, $reference->metadata_source);
        $this->assertSame('2027-03-01', $credential->fresh()->expires_at->toDateString());
    }

    public function test_a_failure_records_the_error_and_keeps_the_last_known_good_timestamp(): void
    {
        [$user, $team, $app, $credential] = $this->externalCredential();
        $verifiedAt = now()->subWeek();
        $credential->vaultReference->update([
            'verification_status' => CredentialVerificationStatus::Verified,
            'verified_at' => $verifiedAt,
        ]);
        $this->authenticate($user, $team, [ApiKeyScope::CredentialsVerify]);

        $this->postJson($this->url($team, $app, $credential), [
            'status' => 'failed',
            'error' => 'AccessDeniedException: not authorized to perform secretsmanager:GetSecretValue',
        ])->assertOk();

        $reference = $credential->fresh()->vaultReference;
        $this->assertSame(CredentialVerificationStatus::Failed, $reference->verification_status);
        $this->assertStringContainsString('AccessDeniedException', $reference->verification_error);
        $this->assertSame(
            $verifiedAt->toDateTimeString(),
            $reference->verified_at->toDateTimeString(),
        );
    }

    public function test_the_failure_webhook_fires_on_the_transition_only(): void
    {
        [$user, $team, $app, $credential] = $this->externalCredential();
        $this->authenticate($user, $team, [ApiKeyScope::CredentialsVerify]);

        $this->postJson($this->url($team, $app, $credential), [
            'status' => 'failed',
            'error' => 'first failure',
        ])->assertOk();

        $this->postJson($this->url($team, $app, $credential), [
            'status' => 'failed',
            'error' => 'second failure',
        ])->assertOk();

        $this->assertSame(1, WebhookEvent::query()
            ->where('event_type', WebhookEventType::CredentialVerificationFailed)
            ->count());
    }

    public function test_recovery_emits_no_event(): void
    {
        [$user, $team, $app, $credential] = $this->externalCredential();
        $this->authenticate($user, $team, [ApiKeyScope::CredentialsVerify]);

        $this->postJson($this->url($team, $app, $credential), ['status' => 'failed', 'error' => 'boom'])
            ->assertOk();
        $this->postJson($this->url($team, $app, $credential), ['status' => 'verified'])
            ->assertOk();

        $this->assertSame(1, WebhookEvent::query()
            ->where('event_type', WebhookEventType::CredentialVerificationFailed)
            ->count());
    }

    public function test_an_error_is_required_with_a_failure_and_prohibited_with_a_success(): void
    {
        [$user, $team, $app, $credential] = $this->externalCredential();
        $this->authenticate($user, $team, [ApiKeyScope::CredentialsVerify]);

        $this->postJson($this->url($team, $app, $credential), ['status' => 'failed'])
            ->assertStatus(422)
            ->assertJsonValidationErrors('error');

        $this->postJson($this->url($team, $app, $credential), [
            'status' => 'verified',
            'error' => 'why is this here',
        ])
            ->assertStatus(422)
            ->assertJsonValidationErrors('error');
    }

    public function test_an_expiry_cannot_accompany_a_failure(): void
    {
        [$user, $team, $app, $credential] = $this->externalCredential();
        $this->authenticate($user, $team, [ApiKeyScope::CredentialsVerify]);

        $this->postJson($this->url($team, $app, $credential), [
            'status' => 'failed',
            'error' => 'boom',
            'expires_at' => '2027-03-01T00:00:00Z',
        ])
            ->assertStatus(422)
            ->assertJsonValidationErrors('expires_at');
    }

    public function test_a_local_credential_cannot_be_verified(): void
    {
        [$user, $team, $app] = $this->externalCredential();
        $local = Credential::factory()->create([
            'team_id' => $team->id,
            'type' => CredentialType::ApnsKey,
        ]);
        $this->authenticate($user, $team, [ApiKeyScope::CredentialsVerify]);

        $this->postJson($this->url($team, $app, $local), ['status' => 'verified'])
            ->assertStatus(422);
    }

    public function test_the_verify_scope_is_required(): void
    {
        [$user, $team, $app, $credential] = $this->externalCredential();
        $this->authenticate($user, $team, [ApiKeyScope::CredentialsRead]);

        $this->postJson($this->url($team, $app, $credential), ['status' => 'verified'])
            ->assertForbidden();
    }

    /** @return array{0: User, 1: Team, 2: MobileApp, 3: Credential} */
    private function externalCredential(): array
    {
        $user = User::factory()->create();
        $team = Team::factory()->create(['owner_id' => $user->id]);
        $team->users()->attach($user, ['role' => 'owner']);
        $app = MobileApp::factory()->create(['team_id' => $team->id]);
        $identifier = AppIdentifier::factory()->create([
            'mobile_app_id' => $app->id,
            'platform' => Platform::Ios,
        ]);
        $credential = Credential::factory()
            ->external(VaultProvider::AwsSecretsManager, 'arn:aws:secretsmanager:eu:1:secret:apns')
            ->create(['team_id' => $team->id, 'type' => CredentialType::ApnsKey]);
        $identifier->credentials()->attach($credential);

        return [$user, $team, $app, $credential];
    }

    private function url(Team $team, MobileApp $app, Credential $credential): string
    {
        return "/api/v1/{$team->slug}/apps/{$app->slug}/credentials/{$credential->id}/verification";
    }

    /** @param list<ApiKeyScope> $scopes */
    private function authenticate(User $user, Team $team, array $scopes): void
    {
        $token = $user->createTeamApiKey(
            $team,
            'Runner',
            array_map(fn (ApiKeyScope $scope): string => $scope->value, $scopes),
        )->plainTextToken;
        $this->app['auth']->guard('sanctum')->forgetUser();
        $this->withToken($token);
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `cd apps/platform && php artisan test --filter=CredentialVerificationApiTest`
Expected: FAIL — `App\Enums\ApiKeyScope::CredentialsVerify` does not exist.

- [ ] **Step 3: Add the scope**

In `app/Enums/ApiKeyScope.php`:

- add `case CredentialsVerify = 'credentials:verify';` immediately after `CredentialsReveal`
- in `presets()`, add `self::CredentialsVerify->value,` to the `ci-cd-pipeline` scope list, after `self::CredentialsReveal->value`
- in `requiredPermission()`, add `self::CredentialsVerify` to the arm that returns `TeamPermission::ManageCredentials` (the same arm as `CredentialsWrite`)
- in the descriptions map, add `self::CredentialsVerify => 'Report whether a vault reference resolved during a build.',`
- in `groups()`, add it to the `credentials` group alongside the other three

- [ ] **Step 4: Add the webhook event**

In `app/Enums/WebhookEventType.php` add, after `CredentialExpired`:

```php
    case CredentialVerificationFailed = 'credential.verification_failed';
```

Add a helper the recorder will need in Task 10:

```php
    public function isCredentialEvent(): bool
    {
        return str_starts_with($this->value, 'credential.');
    }
```

In `app/Integrations/Webhooks/WebhookPayload.php`, extend `credential()` so a subscriber can tell the modes apart:

```php
    public static function credential(Credential $credential): array
    {
        $reference = $credential->vaultReference;

        return [
            'id' => $credential->id,
            'name' => $credential->name,
            'type' => $credential->type->value,
            'storage_mode' => $credential->storage_mode->value,
            'vault' => $reference ? [
                'provider' => $reference->provider->value,
                'resource' => $reference->resource,
                'verification_status' => $reference->verification_status->value,
                'verification_error' => $reference->verification_error,
            ] : null,
            'expires_at' => $credential->expires_at?->toISOString(),
            'created_at' => $credential->created_at?->toISOString(),
            'updated_at' => $credential->updated_at?->toISOString(),
        ];
    }
```

- [ ] **Step 5: Create the action**

`app/Actions/Credentials/RecordVaultVerification.php`:

```php
<?php

namespace App\Actions\Credentials;

use App\Enums\CredentialVerificationStatus;
use App\Enums\VaultMetadataSource;
use App\Enums\WebhookEventType;
use App\Integrations\Webhooks\WebhookEventRecorder;
use App\Integrations\Webhooks\WebhookPayload;
use App\Models\Credential;
use App\Models\CredentialVaultReference;
use App\Models\Team;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

class RecordVaultVerification
{
    public function __construct(
        private readonly WebhookEventRecorder $webhookEvents,
    ) {}

    /**
     * @param  array{status: string, error?: string|null, expires_at?: string|null, metadata?: array<string, string|null>|null}  $data
     */
    public function __invoke(Team $team, Credential $credential, array $data): CredentialVaultReference
    {
        $reference = $credential->vaultReference;
        $status = CredentialVerificationStatus::from($data['status']);
        $wasFailed = $reference->verification_status === CredentialVerificationStatus::Failed;

        return DB::transaction(function () use (
            $team,
            $credential,
            $reference,
            $status,
            $wasFailed,
            $data,
        ): CredentialVaultReference {
            $attributes = [
                'verification_status' => $status,
                'verification_error' => $status === CredentialVerificationStatus::Failed
                    ? $data['error']
                    : null,
            ];

            if ($status === CredentialVerificationStatus::Verified) {
                $attributes['verified_at'] = now();
            }

            if (array_key_exists('metadata', $data) && $data['metadata'] !== null) {
                $attributes['metadata'] = $data['metadata'];
                $attributes['metadata_version'] = $reference->metadata_version + 1;
                $attributes['metadata_source'] = VaultMetadataSource::Pipeline;
            }

            $reference->update($attributes);

            if (filled($data['expires_at'] ?? null)) {
                $credential->update(['expires_at' => Carbon::parse($data['expires_at'])]);
            }

            // Only the transition is newsworthy. A build retrying in a loop
            // would otherwise emit one webhook per attempt.
            if ($status === CredentialVerificationStatus::Failed && ! $wasFailed) {
                $this->webhookEvents->record(
                    $team,
                    WebhookEventType::CredentialVerificationFailed,
                    $credential,
                    WebhookPayload::credential($credential->fresh()),
                );
            }

            return $reference->fresh();
        });
    }
}
```

- [ ] **Step 6: Create the controller**

`app/Http/Controllers/Api/V1/CredentialVerificationController.php`:

```php
<?php

namespace App\Http\Controllers\Api\V1;

use App\Actions\Credentials\RecordVaultVerification;
use App\Enums\CredentialVerificationStatus;
use App\Http\Controllers\Controller;
use App\Models\Credential;
use App\Models\MobileApp;
use App\Models\Team;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class CredentialVerificationController extends Controller
{
    public function __construct(
        private readonly RecordVaultVerification $recordVerification,
    ) {}

    public function store(
        Request $request,
        Team $team,
        MobileApp $mobileApp,
        Credential $credential,
    ): JsonResponse {
        abort_unless($credential->team_id === $team->id, 404);

        if (! $credential->isExternal()) {
            throw ValidationException::withMessages([
                'status' => 'Only a credential stored in your own vault can be verified.',
            ]);
        }

        $data = $request->validate([
            'status' => [
                'required',
                Rule::in([
                    CredentialVerificationStatus::Verified->value,
                    CredentialVerificationStatus::Failed->value,
                ]),
            ],
            'error' => [
                Rule::requiredIf($request->input('status') === CredentialVerificationStatus::Failed->value),
                Rule::prohibitedIf($request->input('status') === CredentialVerificationStatus::Verified->value),
                'string',
                'max:1000',
            ],
            'expires_at' => [
                'nullable',
                'date',
                Rule::prohibitedIf($request->input('status') === CredentialVerificationStatus::Failed->value),
            ],
            'metadata' => ['nullable', 'array'],
            'metadata.*' => ['nullable', 'string', 'max:500'],
        ]);

        $reference = ($this->recordVerification)($team, $credential, $data);

        return response()->json(['data' => [
            'provider' => $reference->provider->value,
            'resource' => $reference->resource,
            'metadata' => $reference->metadata,
            'metadata_version' => $reference->metadata_version,
            'metadata_source' => $reference->metadata_source->value,
            'verification' => [
                'status' => $reference->verification_status->value,
                'verified_at' => $reference->verified_at,
                'error' => $reference->verification_error,
            ],
        ]]);
    }
}
```

- [ ] **Step 7: Register the route**

In `routes/api.php` add the import `use App\Http\Controllers\Api\V1\CredentialVerificationController;` and, directly after the `apps.credentials.show` route:

```php
        Route::post('/apps/{mobileApp:slug}/credentials/{credential}/verification', [CredentialVerificationController::class, 'store'])
            ->middleware([
                'abilities:credentials:verify',
                'team.permission:manage_credentials',
                'throttle:60,1',
            ])
            ->name('apps.credentials.verification.store');
```

- [ ] **Step 8: Run the test to verify it passes**

Run: `cd apps/platform && php artisan test --filter=CredentialVerificationApiTest`
Expected: PASS, 8 tests.

- [ ] **Step 9: Run the ACL and webhook suites**

Run: `cd apps/platform && php artisan test --filter="ExistingRoutesAclTest|ApiKeyScopeTest|Webhook"`
Expected: PASS. `ExistingRoutesAclTest` enumerates routes; if it asserts a fixed count, update that count rather than removing the assertion.

- [ ] **Step 10: Commit**

```bash
git add apps/platform/app/Actions/Credentials/RecordVaultVerification.php \
  apps/platform/app/Http/Controllers/Api/V1/CredentialVerificationController.php \
  apps/platform/app/Enums/WebhookEventType.php apps/platform/app/Enums/ApiKeyScope.php \
  apps/platform/app/Integrations/Webhooks/WebhookPayload.php apps/platform/routes/api.php \
  apps/platform/tests/Feature/Api/V1/CredentialVerificationApiTest.php
git commit -m "feat(api): accept vault verification reports from pipelines"
```

---

## Task 8: `CredentialPresenter` — vault block and the `reveal_url` fix

**Files:**
- Modify: `apps/platform/app/Support/Apps/CredentialPresenter.php`
- Test: `apps/platform/tests/Feature/CredentialPresenterTest.php`

**Interfaces:**
- Consumes: Tasks 1–2.
- Produces: `storage_mode` and `vault` keys in the app-drilldown credential payload; `reveal_url` gated on password fields.

- [ ] **Step 1: Write the failing test**

Create `apps/platform/tests/Feature/CredentialPresenterTest.php`:

```php
<?php

namespace Tests\Feature;

use App\Enums\CredentialType;
use App\Enums\Platform;
use App\Enums\VaultProvider;
use App\Models\AppIdentifier;
use App\Models\Credential;
use App\Models\MobileApp;
use App\Models\Team;
use App\Support\Apps\CredentialPresenter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CredentialPresenterTest extends TestCase
{
    use RefreshDatabase;

    public function test_external_credential_presents_a_vault_block_and_no_reveal_url(): void
    {
        [$team, $identifier] = $this->workspace();
        $credential = Credential::factory()
            ->external(VaultProvider::HashicorpVault, 'secret/data/apns')
            ->create([
                'team_id' => $team->id,
                'type' => CredentialType::ApnsKey,
                'payload' => ['key_id' => 'ABCDEFGHIJ', 'team_id' => 'KLMNOPQRST'],
            ]);

        $presented = CredentialPresenter::present($team, $credential, $identifier, null);

        $this->assertSame('external', $presented['storage_mode']);
        $this->assertSame('hashicorp_vault', $presented['vault']['provider']);
        $this->assertSame('secret/data/apns', $presented['vault']['resource']);
        $this->assertSame('unverified', $presented['vault']['verification']['status']);
        $this->assertNull($presented['file']);
        $this->assertNull($presented['reveal_url'], 'A payload of non-secret fields must not offer a reveal.');
        $this->assertFalse($presented['expo']['available']);
    }

    public function test_local_credential_with_a_password_still_offers_a_reveal_url(): void
    {
        [$team, $identifier] = $this->workspace();
        $credential = Credential::factory()->create([
            'team_id' => $team->id,
            'type' => CredentialType::AndroidKeystore,
            'payload' => [
                'store_password' => 'store-secret',
                'key_alias' => 'release',
                'key_password' => 'key-secret',
            ],
        ]);

        $presented = CredentialPresenter::present($team, $credential, $identifier, null);

        $this->assertSame('local', $presented['storage_mode']);
        $this->assertNull($presented['vault']);
        $this->assertNotNull($presented['reveal_url']);
    }

    public function test_local_credential_without_password_fields_offers_no_reveal_url(): void
    {
        [$team, $identifier] = $this->workspace();
        $credential = Credential::factory()->create([
            'team_id' => $team->id,
            'type' => CredentialType::ApnsKey,
            'payload' => ['key_id' => 'ABCDEFGHIJ', 'team_id' => 'KLMNOPQRST'],
        ]);

        $presented = CredentialPresenter::present($team, $credential, $identifier, null);

        $this->assertNull($presented['reveal_url']);
    }

    /** @return array{0: Team, 1: AppIdentifier} */
    private function workspace(): array
    {
        $team = Team::factory()->create();
        $app = MobileApp::factory()->create(['team_id' => $team->id]);
        $identifier = AppIdentifier::factory()->create([
            'mobile_app_id' => $app->id,
            'platform' => Platform::Ios,
        ]);

        return [$team, $identifier];
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `cd apps/platform && php artisan test --filter=CredentialPresenterTest`
Expected: FAIL — `Undefined array key "storage_mode"`.

- [ ] **Step 3: Update the presenter**

In `app/Support/Apps/CredentialPresenter.php`, compute the password count and the reference near the top of `present()`:

```php
        $reference = $credential->vaultReference;
        $passwordFieldsCount = collect($definition['fields'])
            ->filter(fn (array $field): bool => $field['input'] === 'password'
                && array_key_exists($field['name'], $payload))
            ->count();
```

Replace the `reveal_url` line:

```php
            'reveal_url' => $passwordFieldsCount > 0
                ? route('accounts.credentials.reveal', ['team' => $team, 'credential' => $credential])
                : null,
```

and add, next to `'file' => …`:

```php
            'storage_mode' => $credential->storage_mode->value,
            'vault' => $reference ? [
                'provider' => $reference->provider->value,
                'provider_label' => $reference->provider->label(),
                'resource' => $reference->resource,
                'metadata' => $reference->metadata,
                'metadata_version' => $reference->metadata_version,
                'metadata_source' => $reference->metadata_source->value,
                'verification' => [
                    'status' => $reference->verification_status->value,
                    'verified_at' => $reference->verified_at,
                    'error' => $reference->verification_error,
                ],
            ] : null,
```

This mirrors what `CredentialVaultController::presentCredential()` already does with `password_fields_count`; the two presenters now agree.

- [ ] **Step 4: Run the test to verify it passes**

Run: `cd apps/platform && php artisan test --filter=CredentialPresenterTest`
Expected: PASS, 3 tests.

- [ ] **Step 5: Run the app-drilldown suites**

Run: `cd apps/platform && php artisan test --filter="AppIdentifier|CredentialSharing|CredentialReuse"`
Expected: PASS. If a test asserts a `reveal_url` on a credential with no password fields, that assertion was locking in the defect — update it and note the change in the commit body.

- [ ] **Step 6: Commit**

```bash
git add apps/platform/app/Support/Apps/CredentialPresenter.php \
  apps/platform/tests/Feature/CredentialPresenterTest.php
git commit -m "fix(credentials): only offer a reveal when a payload holds a password"
```

---

## Task 9: Vault page backend — storage filter, verification chip, `missing_file` correction

**Files:**
- Modify: `apps/platform/app/Http/Controllers/CredentialVaultController.php`
- Test: `apps/platform/tests/Feature/CredentialVaultTest.php` (append methods)

**Interfaces:**
- Consumes: Tasks 1–2.
- Produces: new `filters.storage`, new `status` value `verification_failed`, new `stats.verification_failed`, and `storage_mode` / `vault` keys on each row of `credentials.data`. Also `vaultProviders` and `allowedStorageModes` props for the form modal in Task 11.

- [ ] **Step 1: Write the failing test**

Append to `apps/platform/tests/Feature/CredentialVaultTest.php` (add `use App\Enums\CredentialVerificationStatus;` and `use App\Enums\VaultProvider;`):

```php
    public function test_missing_file_stat_and_filter_ignore_external_credentials(): void
    {
        [$owner, $team] = $this->team();
        Credential::factory()->create([
            'team_id' => $team->id,
            'name' => 'Local without a file',
            'type' => CredentialType::ApnsKey,
        ]);
        Credential::factory()
            ->external(VaultProvider::AwsSecretsManager, 'arn:aws:secretsmanager:eu:1:secret:apns')
            ->create(['team_id' => $team->id, 'name' => 'Vault reference', 'type' => CredentialType::ApnsKey]);

        $this->actingAs($owner)
            ->get(route('accounts.credentials.index', $team))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('stats.total', 2)
                ->where('stats.missing_files', 1));

        $this->actingAs($owner)
            ->get(route('accounts.credentials.index', [$team, 'status' => 'missing_file']))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->has('credentials.data', 1)
                ->where('credentials.data.0.name', 'Local without a file'));
    }

    public function test_storage_filter_selects_one_mode(): void
    {
        [$owner, $team] = $this->team();
        Credential::factory()->create(['team_id' => $team->id, 'name' => 'Local one']);
        Credential::factory()
            ->external(VaultProvider::Custom, 'somewhere')
            ->create(['team_id' => $team->id, 'name' => 'External one']);

        $this->actingAs($owner)
            ->get(route('accounts.credentials.index', [$team, 'storage' => 'external']))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->has('credentials.data', 1)
                ->where('credentials.data.0.name', 'External one')
                ->where('credentials.data.0.storage_mode', 'external')
                ->where('credentials.data.0.vault.provider', 'custom')
                ->where('filters.storage', 'external'));
    }

    public function test_an_unknown_storage_filter_falls_back_to_all(): void
    {
        [$owner, $team] = $this->team();
        Credential::factory()->create(['team_id' => $team->id]);
        Credential::factory()->external()->create(['team_id' => $team->id]);

        $this->actingAs($owner)
            ->get(route('accounts.credentials.index', [$team, 'storage' => 'nonsense']))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->has('credentials.data', 2)
                ->where('filters.storage', ''));
    }

    public function test_verification_failed_filter_and_stat(): void
    {
        [$owner, $team] = $this->team();
        $failed = Credential::factory()
            ->external(VaultProvider::Custom, 'broken')
            ->create(['team_id' => $team->id, 'name' => 'Broken reference']);
        $failed->vaultReference->update([
            'verification_status' => CredentialVerificationStatus::Failed,
            'verification_error' => 'AccessDenied',
        ]);
        Credential::factory()->external(VaultProvider::Custom, 'fine')->create(['team_id' => $team->id]);

        $this->actingAs($owner)
            ->get(route('accounts.credentials.index', [$team, 'status' => 'verification_failed']))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('stats.verification_failed', 1)
                ->has('credentials.data', 1)
                ->where('credentials.data.0.name', 'Broken reference')
                ->where('credentials.data.0.vault.verification.status', 'failed')
                ->where('credentials.data.0.vault.verification.error', 'AccessDenied'));
    }

    public function test_the_page_exposes_the_provider_list_and_the_permitted_modes(): void
    {
        [$owner, $team] = $this->team();
        config(['credentials.storage_modes' => 'local,external']);

        $this->actingAs($owner)
            ->get(route('accounts.credentials.index', $team))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('allowedStorageModes', ['local', 'external'])
                ->has('vaultProviders', 6)
                ->where('vaultProviders.0.value', 'aws_secrets_manager'));
    }
```

- [ ] **Step 2: Run test to verify it fails**

Run: `cd apps/platform && php artisan test --filter=CredentialVaultTest`
Expected: FAIL — `stats.missing_files` is 2, not 1.

- [ ] **Step 3: Update the controller**

In `app/Http/Controllers/CredentialVaultController.php` add the imports:

```php
use App\Enums\CredentialStorageMode;
use App\Enums\CredentialVerificationStatus;
use App\Enums\VaultProvider;
use App\Support\Credentials\StorageModePolicy;
```

Add the parsing of the new inputs next to the existing ones:

```php
        $statuses = [
            'valid', 'expiring', 'expired', 'never',
            'unattached', 'missing_file', 'verification_failed',
        ];
```

```php
        $storage = CredentialStorageMode::tryFrom($request->string('storage')->toString());
```

Add the eager load `'vaultReference'` to the `->with([...])` list.

Replace the `missing_file` filter so it only considers local credentials, and add the two new filters:

```php
            ->when($status === 'missing_file', fn (Builder $query) => $query
                ->where('storage_mode', CredentialStorageMode::Local->value)
                ->whereDoesntHave('media', fn (Builder $query) => $query
                    ->where('collection_name', 'file')))
            ->when($status === 'verification_failed', fn (Builder $query) => $query
                ->whereHas('vaultReference', fn (Builder $query) => $query
                    ->where('verification_status', CredentialVerificationStatus::Failed->value)))
            ->when($storage, fn (Builder $query) => $query->where('storage_mode', $storage->value))
```

Apply the same `storage_mode` restriction to the `missing_files` stat, and add the new stat:

```php
                'missing_files' => $team->credentials()
                    ->where('storage_mode', CredentialStorageMode::Local->value)
                    ->whereDoesntHave('media', fn (Builder $query) => $query->where('collection_name', 'file'))
                    ->count(),
                'verification_failed' => $team->credentials()
                    ->whereHas('vaultReference', fn (Builder $query) => $query
                        ->where('verification_status', CredentialVerificationStatus::Failed->value))
                    ->count(),
```

Add `'storage' => $storage?->value ?? '',` to the `filters` array, and two new top-level props:

```php
            'vaultProviders' => VaultProvider::definitions(),
            'allowedStorageModes' => app(StorageModePolicy::class)->allowedValuesFor($team),
```

In `presentCredential()`, add the same block as the API resource:

```php
        $reference = $credential->vaultReference;
```

```php
            'storage_mode' => $credential->storage_mode->value,
            'vault' => $reference ? [
                'provider' => $reference->provider->value,
                'provider_label' => $reference->provider->label(),
                'resource' => $reference->resource,
                'metadata' => $reference->metadata,
                'metadata_version' => $reference->metadata_version,
                'metadata_source' => $reference->metadata_source->value,
                'verification' => [
                    'status' => $reference->verification_status->value,
                    'verified_at' => $reference->verified_at,
                    'error' => $reference->verification_error,
                ],
            ] : null,
```

- [ ] **Step 4: Run the test to verify it passes**

Run: `cd apps/platform && php artisan test --filter=CredentialVaultTest`
Expected: PASS — the five new tests plus every pre-existing one.

- [ ] **Step 5: Commit**

```bash
git add apps/platform/app/Http/Controllers/CredentialVaultController.php \
  apps/platform/tests/Feature/CredentialVaultTest.php
git commit -m "feat(credentials): filter the vault by storage mode and verification state"
```

---

## Task 10: The `dashboard-only` API profile

**Files:**
- Create: `apps/platform/app/Enums/CredentialApiProfile.php`
- Modify: `apps/platform/routes/api.php`
- Modify: `apps/platform/app/Enums/ApiKeyScope.php`
- Modify: `apps/platform/app/Enums/WebhookEventType.php`
- Modify: `apps/platform/app/Integrations/Webhooks/WebhookEventRecorder.php`
- Test: `apps/platform/tests/Feature/Api/V1/CredentialDashboardOnlyProfileTest.php`

**Interfaces:**
- Consumes: `WebhookEventType::isCredentialEvent()` (Task 7).
- Produces: `CredentialApiProfile::current(): self`, `->exposesSecretMaterial(): bool`.

- [ ] **Step 1: Write the failing test**

Create `apps/platform/tests/Feature/Api/V1/CredentialDashboardOnlyProfileTest.php`:

```php
<?php

namespace Tests\Feature\Api\V1;

use App\Enums\ApiKeyScope;
use App\Enums\CredentialType;
use App\Enums\Platform;
use App\Enums\VaultProvider;
use App\Enums\WebhookEventType;
use App\Models\AppIdentifier;
use App\Models\Credential;
use App\Models\MobileApp;
use App\Models\Team;
use App\Models\User;
use App\Models\WebhookEvent;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CredentialDashboardOnlyProfileTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Routes are resolved once, when the application boots, so the profile
     * must be in the environment before parent::setUp() runs. Setting
     * config() inside a test method is too late.
     */
    protected function setUp(): void
    {
        putenv('CREDENTIALS_API_PROFILE=dashboard_only');
        $_ENV['CREDENTIALS_API_PROFILE'] = 'dashboard_only';
        $_SERVER['CREDENTIALS_API_PROFILE'] = 'dashboard_only';

        parent::setUp();
    }

    protected function tearDown(): void
    {
        putenv('CREDENTIALS_API_PROFILE');
        unset($_ENV['CREDENTIALS_API_PROFILE'], $_SERVER['CREDENTIALS_API_PROFILE']);

        parent::tearDown();
    }

    public function test_secret_material_endpoints_are_absent(): void
    {
        [$user, $team, $app, $credential] = $this->workspace();
        $this->authenticate($user, $team, [
            ApiKeyScope::CredentialsRead,
            ApiKeyScope::CredentialsWrite,
            ApiKeyScope::CredentialsReveal,
        ]);

        $base = "/api/v1/{$team->slug}/apps/{$app->slug}/credentials/{$credential->id}";

        $this->postJson("{$base}/reveal")->assertNotFound();
        $this->getJson("{$base}/file")->assertNotFound();
        $this->postJson("{$base}/file")->assertNotFound();
        $this->deleteJson("{$base}/file")->assertNotFound();
    }

    public function test_metadata_endpoints_still_work(): void
    {
        [$user, $team, $app, $credential] = $this->workspace();
        $this->authenticate($user, $team, [ApiKeyScope::CredentialsRead]);

        $this->getJson("/api/v1/{$team->slug}/apps/{$app->slug}/credentials")
            ->assertOk()
            ->assertJsonPath('data.0.vault.resource', 'arn:aws:secretsmanager:eu:1:secret:apns');
    }

    public function test_verification_still_works(): void
    {
        [$user, $team, $app, $credential] = $this->workspace();
        $this->authenticate($user, $team, [ApiKeyScope::CredentialsVerify]);

        $this->postJson(
            "/api/v1/{$team->slug}/apps/{$app->slug}/credentials/{$credential->id}/verification",
            ['status' => 'verified'],
        )->assertOk();
    }

    public function test_the_dashboard_can_still_download(): void
    {
        [$user, $team] = $this->workspace();
        $local = Credential::factory()->create([
            'team_id' => $team->id,
            'type' => CredentialType::ApnsKey,
        ]);

        // No media attached, so 404 is the expected body-level answer — the
        // point of this assertion is that the route still exists on the web
        // side rather than 405/RouteNotFound.
        $this->actingAs($user)
            ->get(route('accounts.credentials.download', [$team, $local]))
            ->assertNotFound();

        $this->assertTrue(
            app('router')->has('accounts.credentials.download'),
            'The dashboard download route must survive the dashboard-only profile.',
        );
    }

    public function test_credential_webhook_events_are_not_recorded(): void
    {
        [$user, $team, $app, $credential] = $this->workspace();
        $this->authenticate($user, $team, [ApiKeyScope::CredentialsVerify]);

        $this->postJson(
            "/api/v1/{$team->slug}/apps/{$app->slug}/credentials/{$credential->id}/verification",
            ['status' => 'failed', 'error' => 'boom'],
        )->assertOk();

        $this->assertSame(0, WebhookEvent::query()
            ->where('event_type', WebhookEventType::CredentialVerificationFailed)
            ->count());
    }

    public function test_credential_events_are_not_subscribable(): void
    {
        $this->assertNotContains(
            WebhookEventType::CredentialExpired->value,
            WebhookEventType::subscribableValues(),
        );
        $this->assertContains(
            WebhookEventType::BuildCompleted->value,
            WebhookEventType::subscribableValues(),
        );
    }

    public function test_the_reveal_scope_is_not_assignable(): void
    {
        $this->assertNotContains(ApiKeyScope::CredentialsReveal->value, ApiKeyScope::assignableValues());
        $this->assertContains(ApiKeyScope::CredentialsRead->value, ApiKeyScope::assignableValues());
    }

    /** @return array{0: User, 1: Team, 2: MobileApp, 3: Credential} */
    private function workspace(): array
    {
        $user = User::factory()->create();
        $team = Team::factory()->create(['owner_id' => $user->id]);
        $team->addUser($user, 'owner');
        $user->update(['current_team_id' => $team->id]);
        $app = MobileApp::factory()->create(['team_id' => $team->id]);
        $identifier = AppIdentifier::factory()->create([
            'mobile_app_id' => $app->id,
            'platform' => Platform::Ios,
        ]);
        $credential = Credential::factory()
            ->external(VaultProvider::AwsSecretsManager, 'arn:aws:secretsmanager:eu:1:secret:apns')
            ->create(['team_id' => $team->id, 'type' => CredentialType::ApnsKey]);
        $identifier->credentials()->attach($credential);

        return [$user->fresh(), $team, $app, $credential];
    }

    /** @param list<ApiKeyScope> $scopes */
    private function authenticate(User $user, Team $team, array $scopes): void
    {
        $token = $user->createTeamApiKey(
            $team,
            'Runner',
            array_map(fn (ApiKeyScope $scope): string => $scope->value, $scopes),
        )->plainTextToken;
        $this->app['auth']->guard('sanctum')->forgetUser();
        $this->withToken($token);
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `cd apps/platform && php artisan test --filter=CredentialDashboardOnlyProfileTest`
Expected: FAIL — `Class "App\Enums\CredentialApiProfile" not found` once the routes file references it; before that, the reveal endpoint answers 200/403 instead of 404.

- [ ] **Step 3: Create the profile enum**

`app/Enums/CredentialApiProfile.php`:

```php
<?php

namespace App\Enums;

enum CredentialApiProfile: string
{
    case Full = 'full';
    case DashboardOnly = 'dashboard_only';

    public static function current(): self
    {
        return self::tryFrom((string) config('credentials.api_profile')) ?? self::Full;
    }

    /**
     * Whether the HTTP API may carry credential secrets — the file bytes, the
     * decrypted payload, and pushes of either to a third party such as Expo.
     */
    public function exposesSecretMaterial(): bool
    {
        return $this === self::Full;
    }
}
```

- [ ] **Step 4: Gate the routes**

In `routes/api.php` add `use App\Enums\CredentialApiProfile;` and wrap the six routes. Delete the existing `reveal`, `file.show`, `file.store`, `file.destroy` lines and the two Expo credential routes, then add in their place:

```php
        if (CredentialApiProfile::current()->exposesSecretMaterial()) {
            Route::post('/apps/{mobileApp:slug}/credentials/{credential}/reveal', [CredentialController::class, 'reveal'])->middleware(['abilities:credentials:reveal', 'team.permission:manage_credentials', 'throttle:30,1'])->name('apps.credentials.reveal');
            Route::get('/apps/{mobileApp:slug}/credentials/{credential}/file', [CredentialController::class, 'download'])->middleware(['abilities:credentials:reveal', 'team.permission:manage_credentials'])->name('apps.credentials.file.show');
            Route::post('/apps/{mobileApp:slug}/credentials/{credential}/file', [CredentialController::class, 'upload'])->middleware(['abilities:credentials:write', 'team.permission:manage_credentials'])->name('apps.credentials.file.store');
            Route::delete('/apps/{mobileApp:slug}/credentials/{credential}/file', [CredentialController::class, 'destroyFile'])->middleware(['abilities:credentials:write', 'team.permission:manage_credentials'])->name('apps.credentials.file.destroy');

            Route::post('/apps/{mobileApp:slug}/expo/identifiers/{appIdentifier}/provision', [ExpoCredentialController::class, 'provision'])
                ->middleware([
                    'abilities:identifiers:write,credentials:write,integrations:write',
                    'team.permission:manage_credentials',
                ])
                ->name('apps.expo.identifiers.provision');
            Route::post('/apps/{mobileApp:slug}/expo/identifiers/{appIdentifier}/credentials/{credential}', [ExpoCredentialController::class, 'store'])
                ->middleware([
                    'abilities:credentials:write,integrations:write',
                    'team.permission:manage_credentials',
                ])
                ->name('apps.expo.credentials.store');
        }
```

Copy the middleware arrays verbatim from the lines being replaced — the Expo blocks in the current file span several lines each, and the snippet above is a compaction, not a redefinition. Verify with `git diff` that no middleware string changed.

`CredentialResource` builds a URL with `route('api.v1.apps.credentials.file.show', …)`, which will now throw when the route is absent. Guard it:

```php
            'file' => $media ? [
                'name' => $media->file_name,
                'mime_type' => $media->mime_type,
                'size' => $media->size,
                'url' => CredentialApiProfile::current()->exposesSecretMaterial()
                    ? route('api.v1.apps.credentials.file.show', [
                        'team' => $request->route('team'),
                        'mobileApp' => $mobileApp,
                        'credential' => $this->resource,
                    ])
                    : null,
            ] : null,
```

- [ ] **Step 5: Filter the scope list and the subscribable events**

Both `ApiKeyScope` and `WebhookEventType` need `use App\Enums\CredentialApiProfile;`. They are in the same namespace, so the import is unnecessary in PHP — but do not fully-qualify the class in the bodies either; a bare `CredentialApiProfile::current()` resolves correctly from within `App\Enums`.

In `app/Enums/ApiKeyScope.php` add:

```php
    /** @return list<string> */
    public static function assignableValues(): array
    {
        if (CredentialApiProfile::current()->exposesSecretMaterial()) {
            return self::values();
        }

        return array_values(array_filter(
            self::values(),
            fn (string $scope): bool => $scope !== self::CredentialsReveal->value,
        ));
    }
```

Use `assignableValues()` wherever the key-creation validation and the key-creation UI currently use `values()` — grep for `ApiKeyScope::values()` and replace only the call sites that offer scopes for selection, **not** the ones that validate an already-issued token. The enum case itself must stay: issued keys hold that string in the database.

Also filter the `ci-cd-pipeline` preset through the same rule, so it does not offer a scope that cannot be used. Replace its `scopes` value with:

```php
                'scopes' => array_values(array_intersect([
                    self::AppsRead->value,
                    self::IdentifiersRead->value,
                    self::AssetsRead->value,
                    self::FilesRead->value,
                    self::CredentialsRead->value,
                    self::CredentialsReveal->value,
                    self::CredentialsVerify->value,
                    self::BuildsWrite->value,
                    self::LogsWrite->value,
                    self::ArtifactsWrite->value,
                ], self::assignableValues())),
```

Leave the `build-runner` preset alone — it holds no credential scopes, so the filter would be a no-op there and the extra call would only obscure the list.

In `app/Enums/WebhookEventType.php`, extend `subscribableValues()`:

```php
    /** @return list<string> */
    public static function subscribableValues(): array
    {
        $credentialsExposed = CredentialApiProfile::current()->exposesSecretMaterial();

        return array_values(array_filter(
            self::values(),
            fn (string $event): bool => $event !== self::WebhookTest->value
                && ($credentialsExposed || ! self::from($event)->isCredentialEvent()),
        ));
    }
```

- [ ] **Step 6: Gate the recorder**

In `app/Integrations/Webhooks/WebhookEventRecorder::record()`, immediately after the Expo auto-sync dispatch (which must keep running — it is internal, not egress):

```php
        if ($eventType->isCredentialEvent()
            && ! CredentialApiProfile::current()->exposesSecretMaterial()) {
            return null;
        }
```

Add `use App\Enums\CredentialApiProfile;` to the imports.

- [ ] **Step 7: Run the test to verify it passes**

Run: `cd apps/platform && php artisan test --filter=CredentialDashboardOnlyProfileTest`
Expected: PASS, 7 tests.

- [ ] **Step 8: Verify the default profile is untouched**

Run: `cd apps/platform && php artisan test`
Expected: the whole suite passes. Every other test runs under `api_profile=full`, so route registration, scope lists and webhook recording must behave exactly as before.

- [ ] **Step 9: Commit**

```bash
git add apps/platform/app/Enums/CredentialApiProfile.php apps/platform/routes/api.php \
  apps/platform/app/Enums/ApiKeyScope.php apps/platform/app/Enums/WebhookEventType.php \
  apps/platform/app/Integrations/Webhooks/WebhookEventRecorder.php \
  apps/platform/app/Http/Resources/Api/V1/CredentialResource.php \
  apps/platform/tests/Feature/Api/V1/CredentialDashboardOnlyProfileTest.php
git commit -m "feat(credentials): add the dashboard-only API profile"
```

---

## Task 11: Credential form modals — runes conversion and the external branch

**Files:**
- Create: `apps/platform/resources/js/Components/VaultReferenceFields.svelte`
- Modify: `apps/platform/resources/js/Pages/Credentials/VaultCredentialFormModal.svelte`
- Modify: `apps/platform/resources/js/Pages/Apps/Modals/CredentialFormModal.svelte`
- Modify: `apps/platform/app/Http/Controllers/AppIdentifierPageController.php` (pass the two new props)

**Interfaces:**
- Consumes: `vaultProviders` and `allowedStorageModes` props (Task 9).
- Produces: a form that posts `storage_mode` and `vault[provider|resource|metadata]`.

**Why the conversion is mandatory:** both files use `export let`, i.e. Svelte legacy mode, in which Inertia's `useForm` store does not re-render `form.errors` or `form.processing`. Every rule added in Tasks 3, 5 and 6 is server-side; without runes the user presses Save and sees nothing at all.

- [ ] **Step 1: Convert `VaultCredentialFormModal.svelte` to runes**

Replace the whole `export let` block with a single `$props()` destructure, and convert every reactive statement:

```svelte
<script>
    import { useForm } from '@inertiajs/svelte';
    import { ShieldCheck, Upload } from '@lucide/svelte';
    import Select from '../../Components/Select.svelte';
    import VaultReferenceFields from '../../Components/VaultReferenceFields.svelte';

    let {
        apps = [],
        credentialTypes = [],
        vaultProviders = [],
        allowedStorageModes = ['local'],
        checkUniquenessByDefault = true,
        editing = null,
        open,
        onClose,
        onSaved,
    } = $props();
</script>
```

Mechanical rules for the conversion, applied throughout the file:

- `export let x = d` → a key in the `$props()` destructure with the same default
- `let x = …` that the template reads and something mutates → `let x = $state(…)`
- `$: y = f(x)` → `const y = $derived(f(x))`
- `$: { … }` side-effect blocks → `$effect(() => { … })`
- `on:click` → `onclick`, `on:submit|preventDefault` → `onsubmit={(event) => { event.preventDefault(); … }}`
- `<slot />` → `{@render children?.()}` if the file has one

**`useForm` in `@inertiajs/svelte` 3.6.1 returns a plain rune-state object, not a store** — `let form = $state({...})` at `node_modules/@inertiajs/svelte/dist/useFormState.svelte.js:114`. Read it as `form.name`, `form.errors.name`, `form.processing`. `$form` does not compile. The repo's already-converted pages, e.g. `resources/js/Pages/Auth/Login.svelte`, use exactly this shape.

- [ ] **Step 2: Create the shared field group**

`apps/platform/resources/js/Components/VaultReferenceFields.svelte`:

```svelte
<script>
    import { Plus, Trash2 } from '@lucide/svelte';
    import Select from './Select.svelte';

    let { form, providers = [] } = $props();

    const selected = $derived(providers.find((p) => p.value === form.vault.provider) ?? providers[0]);

    let rows = $state(
        Object.entries(form.vault.metadata ?? {}).map(([key, value]) => ({ key, value })),
    );

    const syncMetadata = () => {
        form.vault.metadata = Object.fromEntries(
            rows.filter((row) => row.key.trim() !== '').map((row) => [row.key.trim(), row.value]),
        );
    };
</script>

<div class="space-y-4">
    <div>
        <label class="text-xs font-semibold text-slate-600" for="vault-provider">Vault provider</label>
        <Select id="vault-provider" bind:value={form.vault.provider} onchange={syncMetadata}>
            {#each providers as provider (provider.value)}
                <option value={provider.value}>{provider.label}</option>
            {/each}
        </Select>
        {#if form.errors['vault.provider']}
            <p class="mt-1 text-xs text-rose-600">{form.errors['vault.provider']}</p>
        {/if}
    </div>

    <div>
        <label class="text-xs font-semibold text-slate-600" for="vault-resource">Resource</label>
        <input
            id="vault-resource"
            class="w-full font-mono text-xs"
            bind:value={form.vault.resource}
            placeholder={selected?.placeholder ?? ''}
        />
        <p class="mt-1 text-[11px] text-slate-400">
            App Manager never reads this address. Your pipeline resolves it.
        </p>
        {#if form.errors['vault.resource']}
            <p class="mt-1 text-xs text-rose-600">{form.errors['vault.resource']}</p>
        {/if}
    </div>

    <div class="space-y-2">
        <span class="text-xs font-semibold text-slate-600">Storage metadata</span>
        {#each rows as row, index (index)}
            <div class="flex gap-2">
                <input class="w-1/3 font-mono text-xs" placeholder="region" bind:value={row.key} onblur={syncMetadata} />
                <input class="flex-1 font-mono text-xs" placeholder="eu-central-1" bind:value={row.value} onblur={syncMetadata} />
                <button
                    type="button"
                    class="bg-transparent p-0 shadow-none text-slate-400"
                    aria-label="Remove metadata row"
                    onclick={() => { rows = rows.filter((_, i) => i !== index); syncMetadata(); }}
                >
                    <Trash2 size={14} />
                </button>
            </div>
        {/each}
        <button
            type="button"
            class="bg-transparent p-0 shadow-none text-xs text-indigo-600"
            onclick={() => { rows = [...rows, { key: '', value: '' }]; }}
        >
            <Plus size={14} /> Add metadata
        </button>
    </div>
</div>
```

`class="bg-transparent p-0 shadow-none"` on the icon buttons is required: `app.css` has a `@layer base { button { … } }` rule that otherwise paints every button indigo with padding and a shadow.

- [ ] **Step 3: Add the mode switch to both modals**

Extend the `useForm` initialiser with the two new keys:

```js
    const form = useForm({
        /* …existing keys… */
        storage_mode: allowedStorageModes[0],
        vault: { provider: vaultProviders[0]?.value ?? 'custom', resource: '', metadata: {} },
    });
```

Above the type select, render the switch only when there is a choice, and only when creating:

```svelte
{#if allowedStorageModes.length > 1 && !editing}
    <div class="inline-flex rounded-lg border border-slate-200 p-0.5" role="radiogroup" aria-label="Storage mode">
        {#each allowedStorageModes as mode (mode)}
            <button
                type="button"
                role="radio"
                aria-checked={form.storage_mode === mode}
                class="bg-transparent px-3 py-1.5 text-xs font-semibold shadow-none {form.storage_mode === mode ? 'rounded-md bg-indigo-50 text-indigo-700' : 'text-slate-500'}"
                onclick={() => (form.storage_mode = mode)}
            >
                {mode === 'local' ? 'Store in App Manager' : 'Reference my vault'}
            </button>
        {/each}
    </div>
{:else if editing}
    <p class="text-[11px] text-slate-400">
        Storage mode is fixed when a credential is created. To change it, create a new credential and move its attachments.
    </p>
{/if}
```

Then branch the body:

```svelte
{#if form.storage_mode === 'external'}
    <VaultReferenceFields {form} providers={vaultProviders} />
    <label class="text-xs font-semibold text-slate-600" for="expires-at">Expires at (optional)</label>
    <input id="expires-at" type="date" bind:value={form.expires_at} />
{:else}
    <!-- the existing file input and full payload fields, unchanged -->
{/if}
```

Inside the payload loop, skip password fields when the mode is external:

```svelte
{#each (selectedType?.fields ?? []).filter((field) => form.storage_mode !== 'external' || field.input !== 'password') as field (field.name)}
```

- [ ] **Step 4: Repeat for `Apps/Modals/CredentialFormModal.svelte`**

Same three edits — runes conversion, the mode switch, the branch — importing `VaultReferenceFields` from `'../../../Components/VaultReferenceFields.svelte'`. The two modals stay separate files; only the vault field group is shared.

- [ ] **Step 5: Pass the props from the app-drilldown page**

In `app/Http/Controllers/AppIdentifierPageController.php`, add to the Inertia payload alongside `credentialTypes`:

```php
            'vaultProviders' => VaultProvider::definitions(),
            'allowedStorageModes' => app(StorageModePolicy::class)->allowedValuesFor($team),
```

with the imports `use App\Enums\VaultProvider;` and `use App\Support\Credentials\StorageModePolicy;`. Grep for every other controller that renders a page mounting `CredentialFormModal` and add the same two props there — `rg -l "CredentialFormModal" apps/platform/resources/js/Pages` gives the page list, and the matching controller is named after it.

- [ ] **Step 6: Build and verify by hand**

Run: `cd apps/platform && npm run build`
Expected: no Svelte compiler errors. A `legacy mode` or `export let` warning means the conversion is incomplete.

Then run: `cd apps/platform && composer dev`, sign in to the seeded demo team, and check all four:

1. Vault → Add credential shows the mode switch; picking **Reference my vault** hides the file input and every password field.
2. Submitting with an empty resource shows an inline error under the field — **this is the assertion that proves the runes conversion worked.** Under legacy mode nothing appears.
3. A saved external credential appears in the list with its provider and resource.
4. Editing it shows the mode as fixed with the explanatory caption.

- [ ] **Step 7: Commit**

```bash
git add apps/platform/resources/js apps/platform/app/Http/Controllers/AppIdentifierPageController.php
git commit -m "feat(credentials): add the external storage branch to both credential forms"
```

---

## Task 12: Vault page — row rendering, storage select, verification chip

**Files:**
- Modify: `apps/platform/resources/js/Pages/Credentials/Index.svelte`

**Interfaces:**
- Consumes: `storage_mode`, `vault`, `filters.storage`, `stats.verification_failed` (Task 9).

- [ ] **Step 1: Branch the row's second line**

Find the line rendering `{type label} · {N} secure fields · {file name}` and branch it:

```svelte
{#if credential.storage_mode === 'external'}
    <span class="text-[11px] text-slate-400">
        {typeLabel(credential.type)} · {credential.vault.provider_label} ·
        <span class="font-mono">{credential.vault.resource}</span>
    </span>
{:else}
    <!-- the existing line, including the amber "no secure file uploaded" case -->
{/if}
```

The amber "no secure file uploaded" text must not be reachable for an external credential.

- [ ] **Step 2: Add the verification chip in the Validity cell**

Beside the runway meter, following the established rule that colour marks only what needs action — `verified` renders nothing:

```svelte
{#if credential.vault?.verification.status === 'failed'}
    <span class="rounded bg-rose-50 px-1.5 py-0.5 text-[10px] font-semibold text-rose-700"
          title={credential.vault.verification.error}>
        Verification failed
    </span>
{:else if credential.vault?.verification.status === 'unverified'}
    <span class="rounded bg-slate-100 px-1.5 py-0.5 text-[10px] font-semibold text-slate-500">
        Not verified
    </span>
{/if}
```

- [ ] **Step 3: Add the Storage select to the toolbar**

Next to the existing Type and App selects, wired through the same `visitFilters` path the other filters use:

```svelte
<Select bind:value={filterState.storage} onchange={visitFilters} aria-label="Storage">
    <option value="">All storage</option>
    <option value="local">In App Manager</option>
    <option value="external">In my vault</option>
</Select>
```

Add `storage` to the object `visitFilters` serialises into the query string, and seed `filterState.storage` from `filters.storage`.

- [ ] **Step 4: Add the `Verification failed` chip to the rail**

In the chip-rail array, after `No expiry`:

```js
{ label: 'Verification failed', status: 'verification_failed', count: stats.verification_failed, tone: 'rose' }
```

It follows the existing rule: a chip whose count is 0 renders dimmed and `disabled`.

- [ ] **Step 5: Build and verify by hand**

Run: `cd apps/platform && npm run build`
Expected: no errors.

Then, against the seeded demo team plus one external credential created through the form in Task 11:

1. The external row shows provider and resource; no "no secure file uploaded".
2. The `No file` chip count did not increase when the external credential was created.
3. `Storage → In my vault` narrows the list to external credentials and survives a page reload.
4. The `Verification failed` chip is dimmed and disabled at zero.

- [ ] **Step 6: Commit**

```bash
git add apps/platform/resources/js/Pages/Credentials/Index.svelte
git commit -m "feat(credentials): show vault references in the credentials vault"
```

---

## Task 13: Team settings — permitted storage modes

**Files:**
- Modify: `apps/platform/app/Http/Controllers/TeamController.php`
- Modify: `apps/platform/resources/js/Pages/Team/Index.svelte`
- Test: `apps/platform/tests/Feature/CredentialStorageModePolicyTest.php` (append)

- [ ] **Step 1: Write the failing test**

Append to `apps/platform/tests/Feature/CredentialStorageModePolicyTest.php` (adding `use App\Models\User;`):

```php
    public function test_owner_narrows_the_permitted_modes_from_settings(): void
    {
        config(['credentials.storage_modes' => 'local,external']);
        $owner = User::factory()->create();
        $team = Team::factory()->create(['owner_id' => $owner->id]);
        $team->addUser($owner, 'owner');
        $owner->update(['current_team_id' => $team->id]);

        $this->actingAs($owner->fresh())
            ->patch(route('accounts.team.update', $team), [
                'name' => $team->name,
                'check_credential_uniqueness_by_default' => true,
                'allowed_credential_storage_modes' => ['external'],
            ])
            ->assertRedirect();

        $this->assertSame(['external'], $team->fresh()->allowed_credential_storage_modes);
    }

    public function test_a_mode_outside_the_instance_ceiling_is_rejected(): void
    {
        config(['credentials.storage_modes' => 'local']);
        $owner = User::factory()->create();
        $team = Team::factory()->create(['owner_id' => $owner->id]);
        $team->addUser($owner, 'owner');
        $owner->update(['current_team_id' => $team->id]);

        $this->actingAs($owner->fresh())
            ->patch(route('accounts.team.update', $team), [
                'name' => $team->name,
                'allowed_credential_storage_modes' => ['external'],
            ])
            ->assertSessionHasErrors('allowed_credential_storage_modes.0');
    }
```

Before running, confirm the update route name with `php artisan route:list --name=team` and adjust `route('accounts.team.update', …)` and the payload keys to whatever `TeamController::update` actually validates today.

- [ ] **Step 2: Run test to verify it fails**

Run: `cd apps/platform && php artisan test --filter=CredentialStorageModePolicyTest`
Expected: FAIL — the attribute is not validated, so it is not persisted.

- [ ] **Step 3: Validate and persist in `TeamController`**

Add to the rules in `update()`:

```php
            'allowed_credential_storage_modes' => ['sometimes', 'nullable', 'array'],
            'allowed_credential_storage_modes.*' => [
                Rule::in(app(StorageModePolicy::class)->allowedValuesFor($team)),
            ],
```

with `use App\Support\Credentials\StorageModePolicy;` and `use Illuminate\Validation\Rule;`. Because `allowedValuesFor()` is bounded by the instance ceiling, a team can never widen past it.

Add the key to whatever array the controller passes to `$team->update(...)`, and expose the current value plus the ceiling to the page:

```php
            'storageModes' => [
                'instance' => app(StorageModePolicy::class)->instanceModes(),
                'selected' => $team->allowed_credential_storage_modes,
            ],
```

Map `instance` to plain strings before sending: `array_map(fn ($mode) => ['value' => $mode->value, 'label' => $mode->label()], …)`.

- [ ] **Step 4: Add the checkboxes**

In `resources/js/Pages/Team/Index.svelte`, beside the existing uniqueness checkbox:

```svelte
{#if storageModes.instance.length > 1}
    <fieldset class="space-y-2">
        <legend class="text-xs font-semibold text-slate-600">Permitted credential storage</legend>
        {#each storageModes.instance as mode (mode.value)}
            <label class="flex items-center gap-2 text-xs">
                <input
                    type="checkbox"
                    value={mode.value}
                    checked={(form.allowed_credential_storage_modes ?? storageModes.instance.map((m) => m.value)).includes(mode.value)}
                    onchange={(event) => toggleStorageMode(mode.value, event.currentTarget.checked)}
                />
                {mode.label}
            </label>
        {/each}
        <p class="text-[11px] text-slate-400">
            Unchecking everything leaves the instance default in force.
        </p>
    </fieldset>
{/if}
```

with a `toggleStorageMode(value, checked)` helper that adds or removes the value from `form.allowed_credential_storage_modes`.

The `{#if}` guard implements the spec's rule: when the instance permits only one mode, the block is not rendered at all.

- [ ] **Step 5: Run the test to verify it passes**

Run: `cd apps/platform && php artisan test --filter=CredentialStorageModePolicyTest`
Expected: PASS, 8 tests.

- [ ] **Step 6: Commit**

```bash
git add apps/platform/app/Http/Controllers/TeamController.php \
  apps/platform/resources/js/Pages/Team/Index.svelte \
  apps/platform/tests/Feature/CredentialStorageModePolicyTest.php
git commit -m "feat(teams): let a team narrow its permitted credential storage modes"
```

---

## Task 14: CLI and fastlane plugin

**Files:**
- Modify: `libs/cli/lib/commands/credentials.sh`
- Modify: `libs/cli/tests/test_credentials.sh`
- Modify: `libs/fastlane-plugin/lib/fastlane/plugin/app_manager/actions/app_manager_list_credentials_action.rb`
- Create: `libs/fastlane-plugin/lib/fastlane/plugin/app_manager/actions/app_manager_report_credential_verification_action.rb`

- [ ] **Step 1: Add the storage columns to `credentials list`**

In `_credentials_list()`, replace the format string:

```bash
  local fmt='{"type":"table","fields":["id","credential_type.slug","storage_mode","vault.resource","build_profile","created_at"],"headers":["id","type","storage","resource","profile","created_at"]}'
```

- [ ] **Step 2: Add the `verify` action**

Add `verify` to `credentials_usage()` under Actions:

```
  verify              Report whether a vault reference resolved
```

and to the option list:

```
  --status STATUS     ok or failed (verify)
  --error MESSAGE     Failure reason, required with --status failed (verify)
  --expires-at DATE   Actual expiry discovered while resolving (verify)
```

Add `verify) _credentials_verify "$@" ;;` to the `case` in `command_credentials`, extend the `die` message at the bottom of that `case` to list `verify`, and add the function, modelled on the existing `_credentials_upload`:

```bash
_credentials_verify() {
  local app="${APP:-}" credential_id="" status="" error="" expires_at=""

  while [ "$#" -gt 0 ]; do
    case "$1" in
      --app) require_value "$1" "$2"; app="$2"; shift 2 ;;
      --app=*) app=${1#*=}; shift ;;
      --credential-id) require_value "$1" "$2"; credential_id="$2"; shift 2 ;;
      --credential-id=*) credential_id=${1#*=}; shift ;;
      --status) require_value "$1" "$2"; status="$2"; shift 2 ;;
      --status=*) status=${1#*=}; shift ;;
      --error) require_value "$1" "$2"; error="$2"; shift 2 ;;
      --error=*) error=${1#*=}; shift ;;
      --expires-at) require_value "$1" "$2"; expires_at="$2"; shift 2 ;;
      --expires-at=*) expires_at=${1#*=}; shift ;;
      --dry-run) DRY_RUN=1; shift ;;
      --json) PRETTY_JSON=1; shift ;;
      -h|--help)
        printf 'Usage: appmctl credentials verify --credential-id ID --status ok|failed [--error MESSAGE] [--expires-at DATE]\n'
        return 0
        ;;
      *) die "unknown credentials verify option: $1" ;;
    esac
  done

  [ -n "$credential_id" ] || die "credentials verify requires --credential-id"
  case "$status" in
    ok|failed) ;;
    *) die "credentials verify requires --status ok or --status failed" ;;
  esac
  [ "$status" = "failed" ] && [ -z "$error" ] && die "--status failed requires --error"

  APP="$app"
  require_app

  local api_status="verified"
  [ "$status" = "failed" ] && api_status="failed"

  local body
  body=$(jq -n \
    --arg status "$api_status" \
    --arg error "$error" \
    --arg expires_at "$expires_at" \
    '{status: $status}
     + (if $error == "" then {} else {error: $error} end)
     + (if $expires_at == "" then {} else {expires_at: $expires_at} end)')

  api_post_json "apps/$APP/credentials/$credential_id/verification" "$body" || return 1
  emit_formatted_response "$LAST_RESPONSE_FILE" '{"type":"json"}'
}
```

Check the exact name of the JSON-posting helper first — `grep -n "api_post" libs/cli/lib/*.sh` — and use whatever the other write commands call. The `ok` → `verified` translation exists because `ok` reads better on a command line while the API speaks the enum.

- [ ] **Step 3: Add a CLI test**

Append to `libs/cli/tests/test_credentials.sh`, following the dry-run assertion style already in that file: assert that `credentials verify --credential-id X --status ok --dry-run` prints `method: POST` and a URL ending in `/verification`, and that omitting `--error` with `--status failed` exits non-zero.

Run: `bash libs/cli/tests/test_credentials.sh`
Expected: PASS.

- [ ] **Step 4: Extend the fastlane list action**

In `app_manager_list_credentials_action.rb`, widen the table:

```ruby
      def self.print_credentials_table(credentials)
        return if credentials.empty?

        UI.message("")
        UI.message("  Credentials (#{credentials.length}):")
        UI.message("  #{'ID'.ljust(38)} #{'Type'.ljust(28)} #{'Storage'.ljust(9)} #{'Name'}")
        UI.message("  #{'-' * 38} #{'-' * 28} #{'-' * 9} #{'-' * 30}")
        credentials.each do |cred|
          id = cred["id"].to_s.ljust(38)
          type = cred["type"].to_s.ljust(28)
          storage = cred["storage_mode"].to_s.ljust(9)
          name = cred["name"].to_s
          UI.message("  #{id} #{type} #{storage} #{name}")
          vault = cred["vault"]
          UI.message("  #{' ' * 38} #{' ' * 28} #{' ' * 9} ↳ #{vault['provider']}: #{vault['resource']}") if vault
        end
        UI.message("")
      end
```

The existing `reject { |key, _| key.to_s == "payload" }` guard stays as it is: `vault` carries no secret, so it belongs in lane context.

- [ ] **Step 5: Create the verification action**

`libs/fastlane-plugin/lib/fastlane/plugin/app_manager/actions/app_manager_report_credential_verification_action.rb`:

```ruby
module Fastlane
  module Actions
    class AppManagerReportCredentialVerificationAction < Action
      def self.run(params)
        body = { status: params[:status] }
        body[:error] = params[:error] if params[:error]
        body[:expires_at] = params[:expires_at] if params[:expires_at]
        body[:metadata] = params[:metadata] if params[:metadata]

        Helper::AppManagerHelper.report_credential_verification(params[:credential_id], body)
        UI.success("Reported #{params[:status]} for credential #{params[:credential_id]}")
      end

      def self.description
        "Reports whether a vault reference resolved during this build"
      end

      def self.details
        "App Manager stores only a reference to a credential kept in your own vault. " \
        "Call this after resolving that reference so the vault page can show whether " \
        "it still works, and so a failure raises a credential.verification_failed webhook."
      end

      def self.available_options
        [
          FastlaneCore::ConfigItem.new(
            key: :credential_id,
            env_name: "APP_MANAGER_CREDENTIAL_ID",
            description: "The credential whose vault reference was resolved",
            type: String,
          ),
          FastlaneCore::ConfigItem.new(
            key: :status,
            description: "verified or failed",
            type: String,
            verify_block: proc do |value|
              UI.user_error!("status must be 'verified' or 'failed'") unless %w[verified failed].include?(value)
            end,
          ),
          FastlaneCore::ConfigItem.new(
            key: :error,
            description: "Failure reason, required when status is failed",
            type: String,
            optional: true,
          ),
          FastlaneCore::ConfigItem.new(
            key: :expires_at,
            description: "Actual expiry discovered while resolving the secret (ISO 8601)",
            type: String,
            optional: true,
          ),
          FastlaneCore::ConfigItem.new(
            key: :metadata,
            description: "Storage metadata to record, such as the resolved version id",
            type: Hash,
            optional: true,
          ),
        ]
      end

      def self.authors
        ["App Manager contributors"]
      end

      def self.is_supported?(_platform)
        true
      end
    end
  end
end
```

Add the matching `report_credential_verification(credential_id, body)` method to `Helper::AppManagerHelper`, next to `list_credentials`, POSTing to `apps/#{app}/credentials/#{credential_id}/verification`. Read the existing helper first and match how it builds the URL and the auth header.

- [ ] **Step 6: Commit**

```bash
git add libs/cli libs/fastlane-plugin
git commit -m "feat(cli,fastlane): show vault references and report verification"
```

---

## Task 15: Documentation

**Files:**
- Create: `apps/docs` page — a "Bring your own vault" concept page
- Modify: the API reference pages covering credentials
- Modify: `README.md` and `apps/platform/README.md` configuration sections

- [ ] **Step 1: Find the docs structure**

Run: `ls apps/docs && rg -l "credentials" apps/docs --glob '!node_modules'`
Read one neighbouring concept page to match its frontmatter and heading style before writing.

- [ ] **Step 2: Write the concept page**

Cover, in this order:

1. What BYOV is: App Manager holds the address, your pipeline holds the means to resolve it.
2. The two modes, and that the mode is fixed at creation.
3. Which fields are still collected in external mode, with the per-type table from the spec.
4. The verification loop: read `vault.resource` → resolve → `POST …/verification`, with a `curl` example and the fastlane action.
5. The `credentials:verify` scope and why it is separate from `credentials:write`.
6. What `verified_at` does and does not mean — "a pipeline resolved this once", not "this is valid now".

- [ ] **Step 3: Document the two config variables**

`CREDENTIAL_STORAGE_MODES` and `CREDENTIALS_API_PROFILE`, each with its default, its accepted values, and the team-level narrowing.

Also document one constraint that is easy to trip over: **every value in `vault.metadata` must be a string.** Laravel's `string` rule is a strict `is_string()` check with no coercion, so a client sending `{"metadata": {"port": 5432}}` gets a 422. Quote numeric and boolean values. This applies both when creating a credential and when a pipeline reports metadata to the verification endpoint.

While writing this, fix the inaccurate rationale comment above the `vault.metadata.*` rule in `app/Support/Credentials/CredentialSchema.php` — it currently claims Laravel coerces scalars, which is wrong. Replace it with: `// Strict is_string(): callers must quote numeric and boolean metadata values.`

Two warnings must be explicit:

- **Switching `CREDENTIALS_API_PROFILE` is a deploy, not a toggle.** Routes resolve at boot.
- **`dashboard_only` + `local` will break your builds.** The pipeline cannot fetch the file at all. That combination only makes sense if secrets reach CI by some other channel; `dashboard_only` + `external` is the coherent pairing.

Also list exactly what `dashboard_only` removes: the four credential file/reveal endpoints, the two Expo credential endpoints, the `credentials:reveal` scope, and all eight `credential.*` webhook events.

- [ ] **Step 4: Document the endpoint**

Add `POST /apps/{app}/credentials/{credential}/verification` to the API reference with its request body, its validation rules, its 422 for a local credential, and the `storage_mode` / `vault` keys added to every credential response.

- [ ] **Step 5: Commit**

```bash
git add apps/docs README.md apps/platform/README.md
git commit -m "docs(credentials): document BYOV, the verification loop, and the API profiles"
```

---

## Final verification

- [ ] **Run the whole platform suite**

Run: `cd apps/platform && composer test`
Expected: PASS with no skipped credential tests.

- [ ] **Run the CLI tests**

Run: `bash libs/cli/tests/test_credentials.sh`
Expected: PASS.

- [ ] **Check formatting**

Run: `cd apps/platform && ./vendor/bin/pint --test && npm run build`
Expected: no style violations, no Svelte compiler warnings about legacy mode.

- [ ] **Exercise the dashboard-only profile once by hand**

Set `CREDENTIALS_API_PROFILE=dashboard_only` in `.env`, run `php artisan config:clear`, restart the server, then confirm:

1. `php artisan route:list --path=credentials` no longer lists `reveal` or `file`.
2. The vault page still reveals and downloads normally.
3. Creating a webhook endpoint offers no `credential.*` events.

Reset the variable to `full` afterwards.

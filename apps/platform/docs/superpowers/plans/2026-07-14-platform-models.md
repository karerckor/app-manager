# Platform Data Model Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Add the platform data model — MobileApp, AppIdentifier, Profile, Credential, and a polymorphic sharing pivot — as Laravel migrations, Eloquent models, enums, and factories, with Spatie Media Library handling all file storage.

**Architecture:** Team-owned MobileApp → AppIdentifier → Profile hierarchy. Credentials are a single polymorphic table shared many-to-many via a `credentialables` morph pivot; most credential types bind to `AppIdentifier`, provisioning profiles bind to `Profile`. App icon, splash icon, and credential files are stored through Spatie Media Library single-file collections.

**Tech Stack:** Laravel 13, PHP 8.3, PHPUnit + `RefreshDatabase` (sqlite `:memory:`), `spatie/laravel-medialibrary`.

## Global Constraints

- All commands run from `apps/platform/` (the Laravel app root).
- Laravel 13 / PHP 8.3. Model config uses PHP attributes (`#[Fillable]`) matching existing `User`/`Team`.
- Casts are declared via a `protected function casts(): array` method (matches `User`).
- New migration timestamps must sort AFTER the existing `2026_07_14_080000_create_teams_tables.php`.
- Tests are PHPUnit classes under `tests/Feature/`, extend `Tests\TestCase`, and use `Illuminate\Foundation\Testing\RefreshDatabase`.
- Run a single test class with: `php artisan test --filter=<ClassName>`.
- The spec is the source of truth: `docs/superpowers/specs/2026-07-14-platform-models-design.md`.

---

### Task 0: Initialize git and install Spatie Media Library

**Files:**
- Create: (none — package + published migration)
- Modify: `composer.json` (via composer require)

**Interfaces:**
- Consumes: nothing
- Produces: a `media` table available after migration; the `Spatie\MediaLibrary\HasMedia` interface and `Spatie\MediaLibrary\InteractsWithMedia` trait usable by later tasks.

- [ ] **Step 1: Initialize git (repo does not yet exist)**

```bash
git init
git add -A
git commit -m "chore: baseline before platform models"
```

- [ ] **Step 2: Require Spatie Media Library**

```bash
composer require "spatie/laravel-medialibrary"
```

Expected: composer resolves and installs the package into `vendor/spatie/laravel-medialibrary`.

- [ ] **Step 3: Publish the media migration**

```bash
php artisan vendor:publish --tag=medialibrary-migrations
```

Expected: a new `..._create_media_table.php` appears in `database/migrations/`.

- [ ] **Step 4: Verify migrations run and the media table exists**

Create `tests/Feature/MediaLibraryInstallTest.php`:

```php
<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class MediaLibraryInstallTest extends TestCase
{
    use RefreshDatabase;

    public function test_media_table_exists(): void
    {
        $this->assertTrue(Schema::hasTable('media'));
    }
}
```

- [ ] **Step 5: Run the test**

Run: `php artisan test --filter=MediaLibraryInstallTest`
Expected: PASS.

- [ ] **Step 6: Commit**

```bash
git add composer.json composer.lock database/migrations tests/Feature/MediaLibraryInstallTest.php
git commit -m "chore: install spatie media library"
```

---

### Task 1: Enums

**Files:**
- Create: `app/Enums/Platform.php`
- Create: `app/Enums/ProfileType.php`
- Create: `app/Enums/CredentialType.php`
- Test: `tests/Feature/EnumsTest.php`

**Interfaces:**
- Consumes: nothing
- Produces:
  - `App\Enums\Platform: string` — cases `Ios='ios'`, `Android='android'`
  - `App\Enums\ProfileType: string` — cases `AppStore='appstore'`, `Development='development'`, `Enterprise='enterprise'`, `AdHoc='adhoc'`, `Production='production'`, `Preview='preview'`
  - `App\Enums\CredentialType: string` — cases `IosCertificate='ios_certificate'`, `ProvisioningProfile='provisioning_profile'`, `ApnsKey='apns_key'`, `AppleApiKey='apple_api_key'`, `AndroidKeystore='android_keystore'`, `FirebaseConfig='firebase_config'`, `GooglePlayServiceAccount='google_play_service_account'`

- [ ] **Step 1: Write the failing test**

Create `tests/Feature/EnumsTest.php`:

```php
<?php

namespace Tests\Feature;

use App\Enums\CredentialType;
use App\Enums\Platform;
use App\Enums\ProfileType;
use Tests\TestCase;

class EnumsTest extends TestCase
{
    public function test_platform_values(): void
    {
        $this->assertSame('ios', Platform::Ios->value);
        $this->assertSame('android', Platform::Android->value);
    }

    public function test_profile_type_values(): void
    {
        $this->assertSame('appstore', ProfileType::AppStore->value);
        $this->assertSame('adhoc', ProfileType::AdHoc->value);
        $this->assertSame('preview', ProfileType::Preview->value);
        $this->assertSame(ProfileType::Development, ProfileType::from('development'));
    }

    public function test_credential_type_values(): void
    {
        $this->assertSame('ios_certificate', CredentialType::IosCertificate->value);
        $this->assertSame('provisioning_profile', CredentialType::ProvisioningProfile->value);
        $this->assertSame('google_play_service_account', CredentialType::GooglePlayServiceAccount->value);
        $this->assertCount(7, CredentialType::cases());
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `php artisan test --filter=EnumsTest`
Expected: FAIL — `Class "App\Enums\Platform" not found`.

- [ ] **Step 3: Create the enums**

`app/Enums/Platform.php`:

```php
<?php

namespace App\Enums;

enum Platform: string
{
    case Ios = 'ios';
    case Android = 'android';
}
```

`app/Enums/ProfileType.php`:

```php
<?php

namespace App\Enums;

enum ProfileType: string
{
    // iOS
    case AppStore = 'appstore';
    case Development = 'development'; // reused by Android
    case Enterprise = 'enterprise';
    case AdHoc = 'adhoc';
    // Android
    case Production = 'production';
    case Preview = 'preview';
}
```

`app/Enums/CredentialType.php`:

```php
<?php

namespace App\Enums;

enum CredentialType: string
{
    case IosCertificate = 'ios_certificate';
    case ProvisioningProfile = 'provisioning_profile';
    case ApnsKey = 'apns_key';
    case AppleApiKey = 'apple_api_key';                  // App Store Connect / TestFlight
    case AndroidKeystore = 'android_keystore';
    case FirebaseConfig = 'firebase_config';             // Firebase HTTP v1
    case GooglePlayServiceAccount = 'google_play_service_account';
}
```

- [ ] **Step 4: Run test to verify it passes**

Run: `php artisan test --filter=EnumsTest`
Expected: PASS.

- [ ] **Step 5: Commit**

```bash
git add app/Enums tests/Feature/EnumsTest.php
git commit -m "feat: add platform, profile type, and credential type enums"
```

---

### Task 2: MobileApp model, migration, factory, and media collections

**Files:**
- Create: `database/migrations/2026_07_14_090000_create_mobile_apps_table.php`
- Create: `app/Models/MobileApp.php`
- Create: `database/factories/MobileAppFactory.php`
- Create: `database/factories/TeamFactory.php` (needed so `Team::factory()` resolves for `MobileAppFactory`)
- Test: `tests/Feature/MobileAppTest.php`

**Interfaces:**
- Consumes: existing `App\Models\Team`, existing `App\Models\User`.
- Produces:
  - `App\Models\MobileApp` implementing `HasMedia`, with `team(): BelongsTo`, `appIdentifiers(): HasMany`, media collections `app_icon` and `splash_icon` (single-file). Fillable: `team_id`, `slug`, `name`, `description`.
  - `Database\Factories\TeamFactory` producing a `Team` with `owner_id` from `User::factory()`.
  - `Database\Factories\MobileAppFactory` producing a `MobileApp` with `team_id` from `Team::factory()`, unique `slug`, `name`, `description`.

- [ ] **Step 1: Write the failing test**

Create `tests/Feature/MobileAppTest.php`:

```php
<?php

namespace Tests\Feature;

use App\Models\MobileApp;
use App\Models\Team;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class MobileAppTest extends TestCase
{
    use RefreshDatabase;

    public function test_mobile_app_belongs_to_a_team(): void
    {
        $app = MobileApp::factory()->create();

        $this->assertInstanceOf(Team::class, $app->team);
    }

    public function test_slug_is_unique_per_team(): void
    {
        $team = Team::factory()->create();
        MobileApp::factory()->create(['team_id' => $team->id, 'slug' => 'my-app']);

        $this->expectException(\Illuminate\Database\QueryException::class);
        MobileApp::factory()->create(['team_id' => $team->id, 'slug' => 'my-app']);
    }

    public function test_registers_icon_media_collections_as_single_file(): void
    {
        $app = MobileApp::factory()->create();
        $names = $app->getRegisteredMediaCollections()->pluck('name');

        $this->assertTrue($names->contains('app_icon'));
        $this->assertTrue($names->contains('splash_icon'));
        $appIcon = $app->getRegisteredMediaCollections()->firstWhere('name', 'app_icon');
        $this->assertTrue($appIcon->singleFile);
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `php artisan test --filter=MobileAppTest`
Expected: FAIL — `Class "App\Models\MobileApp" not found`.

- [ ] **Step 3: Create the migration**

`database/migrations/2026_07_14_090000_create_mobile_apps_table.php`:

```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('mobile_apps', function (Blueprint $table) {
            $table->id();
            $table->foreignId('team_id')->constrained()->cascadeOnDelete();
            $table->string('slug');
            $table->string('name');
            $table->text('description')->nullable();
            $table->timestamps();
            $table->unique(['team_id', 'slug']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('mobile_apps');
    }
};
```

- [ ] **Step 4: Create the TeamFactory**

`database/factories/TeamFactory.php`:

```php
<?php

namespace Database\Factories;

use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<\App\Models\Team>
 */
class TeamFactory extends Factory
{
    public function definition(): array
    {
        return [
            'name' => fake()->company(),
            'owner_id' => User::factory(),
        ];
    }
}
```

- [ ] **Step 5: Create the MobileApp model**

`app/Models/MobileApp.php`:

```php
<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Spatie\MediaLibrary\HasMedia;
use Spatie\MediaLibrary\InteractsWithMedia;

#[Fillable(['team_id', 'slug', 'name', 'description'])]
class MobileApp extends Model implements HasMedia
{
    use HasFactory, InteractsWithMedia;

    public function team(): BelongsTo
    {
        return $this->belongsTo(Team::class);
    }

    public function appIdentifiers(): HasMany
    {
        return $this->hasMany(AppIdentifier::class);
    }

    public function registerMediaCollections(): void
    {
        $this->addMediaCollection('app_icon')->singleFile();
        $this->addMediaCollection('splash_icon')->singleFile();
    }
}
```

- [ ] **Step 6: Create the MobileAppFactory**

`database/factories/MobileAppFactory.php`:

```php
<?php

namespace Database\Factories;

use App\Models\Team;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<\App\Models\MobileApp>
 */
class MobileAppFactory extends Factory
{
    public function definition(): array
    {
        $name = fake()->unique()->words(2, true);

        return [
            'team_id' => Team::factory(),
            'slug' => Str::slug($name).'-'.fake()->unique()->numberBetween(1, 999999),
            'name' => ucfirst($name),
            'description' => fake()->sentence(),
        ];
    }
}
```

- [ ] **Step 7: Run test to verify it passes**

Run: `php artisan test --filter=MobileAppTest`
Expected: PASS.

- [ ] **Step 8: Commit**

```bash
git add database/migrations/2026_07_14_090000_create_mobile_apps_table.php app/Models/MobileApp.php database/factories/MobileAppFactory.php database/factories/TeamFactory.php tests/Feature/MobileAppTest.php
git commit -m "feat: add MobileApp model with team relation and media collections"
```

---

### Task 3: AppIdentifier model, migration, and factory

**Files:**
- Create: `database/migrations/2026_07_14_090100_create_app_identifiers_table.php`
- Create: `app/Models/AppIdentifier.php`
- Create: `database/factories/AppIdentifierFactory.php`
- Test: `tests/Feature/AppIdentifierTest.php`

**Interfaces:**
- Consumes: `App\Models\MobileApp`, `App\Enums\Platform`.
- Produces:
  - `App\Models\AppIdentifier` with `mobileApp(): BelongsTo`, `profiles(): HasMany`, cast `platform => Platform::class`. Fillable: `mobile_app_id`, `platform`, `identifier`, `name`.
  - `Database\Factories\AppIdentifierFactory` with `mobile_app_id` from `MobileApp::factory()`, `platform` defaulting to `Platform::Ios`, unique `identifier`.

- [ ] **Step 1: Write the failing test**

Create `tests/Feature/AppIdentifierTest.php`:

```php
<?php

namespace Tests\Feature;

use App\Enums\Platform;
use App\Models\AppIdentifier;
use App\Models\MobileApp;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AppIdentifierTest extends TestCase
{
    use RefreshDatabase;

    public function test_belongs_to_mobile_app(): void
    {
        $identifier = AppIdentifier::factory()->create();

        $this->assertInstanceOf(MobileApp::class, $identifier->mobileApp);
    }

    public function test_platform_is_cast_to_enum(): void
    {
        $identifier = AppIdentifier::factory()->create(['platform' => Platform::Android]);

        $this->assertSame(Platform::Android, $identifier->fresh()->platform);
    }

    public function test_identifier_is_unique_per_app_and_platform(): void
    {
        $app = MobileApp::factory()->create();
        AppIdentifier::factory()->create([
            'mobile_app_id' => $app->id,
            'platform' => Platform::Ios,
            'identifier' => 'com.example.app',
        ]);

        $this->expectException(\Illuminate\Database\QueryException::class);
        AppIdentifier::factory()->create([
            'mobile_app_id' => $app->id,
            'platform' => Platform::Ios,
            'identifier' => 'com.example.app',
        ]);
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `php artisan test --filter=AppIdentifierTest`
Expected: FAIL — `Class "App\Models\AppIdentifier" not found`.

- [ ] **Step 3: Create the migration**

`database/migrations/2026_07_14_090100_create_app_identifiers_table.php`:

```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('app_identifiers', function (Blueprint $table) {
            $table->id();
            $table->foreignId('mobile_app_id')->constrained()->cascadeOnDelete();
            $table->string('platform');       // Platform enum
            $table->string('identifier');     // bundle id / package name
            $table->string('name')->nullable();
            $table->timestamps();
            $table->unique(['mobile_app_id', 'platform', 'identifier']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('app_identifiers');
    }
};
```

- [ ] **Step 4: Create the model**

`app/Models/AppIdentifier.php`:

```php
<?php

namespace App\Models;

use App\Enums\Platform;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable(['mobile_app_id', 'platform', 'identifier', 'name'])]
class AppIdentifier extends Model
{
    use HasFactory;

    public function mobileApp(): BelongsTo
    {
        return $this->belongsTo(MobileApp::class);
    }

    public function profiles(): HasMany
    {
        return $this->hasMany(Profile::class);
    }

    protected function casts(): array
    {
        return [
            'platform' => Platform::class,
        ];
    }
}
```

- [ ] **Step 5: Create the factory**

`database/factories/AppIdentifierFactory.php`:

```php
<?php

namespace Database\Factories;

use App\Enums\Platform;
use App\Models\MobileApp;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<\App\Models\AppIdentifier>
 */
class AppIdentifierFactory extends Factory
{
    public function definition(): array
    {
        return [
            'mobile_app_id' => MobileApp::factory(),
            'platform' => Platform::Ios,
            'identifier' => 'com.example.'.fake()->unique()->word().fake()->unique()->numberBetween(1, 999999),
            'name' => fake()->words(2, true),
        ];
    }
}
```

- [ ] **Step 6: Run test to verify it passes**

Run: `php artisan test --filter=AppIdentifierTest`
Expected: PASS.

- [ ] **Step 7: Commit**

```bash
git add database/migrations/2026_07_14_090100_create_app_identifiers_table.php app/Models/AppIdentifier.php database/factories/AppIdentifierFactory.php tests/Feature/AppIdentifierTest.php
git commit -m "feat: add AppIdentifier model with platform enum and profiles relation"
```

---

### Task 4: Profile model, migration, and factory

**Files:**
- Create: `database/migrations/2026_07_14_090200_create_profiles_table.php`
- Create: `app/Models/Profile.php`
- Create: `database/factories/ProfileFactory.php`
- Test: `tests/Feature/ProfileTest.php`

**Interfaces:**
- Consumes: `App\Models\AppIdentifier`, `App\Enums\ProfileType`.
- Produces:
  - `App\Models\Profile` with `appIdentifier(): BelongsTo`, cast `type => ProfileType::class`. Fillable: `app_identifier_id`, `type`, `name`.
  - `Database\Factories\ProfileFactory` with `app_identifier_id` from `AppIdentifier::factory()`, `type` defaulting to `ProfileType::AppStore`.

- [ ] **Step 1: Write the failing test**

Create `tests/Feature/ProfileTest.php`:

```php
<?php

namespace Tests\Feature;

use App\Enums\ProfileType;
use App\Models\AppIdentifier;
use App\Models\Profile;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ProfileTest extends TestCase
{
    use RefreshDatabase;

    public function test_belongs_to_app_identifier(): void
    {
        $profile = Profile::factory()->create();

        $this->assertInstanceOf(AppIdentifier::class, $profile->appIdentifier);
    }

    public function test_type_is_cast_to_enum(): void
    {
        $profile = Profile::factory()->create(['type' => ProfileType::AdHoc]);

        $this->assertSame(ProfileType::AdHoc, $profile->fresh()->type);
    }

    public function test_type_is_unique_per_app_identifier(): void
    {
        $identifier = AppIdentifier::factory()->create();
        Profile::factory()->create([
            'app_identifier_id' => $identifier->id,
            'type' => ProfileType::AppStore,
        ]);

        $this->expectException(\Illuminate\Database\QueryException::class);
        Profile::factory()->create([
            'app_identifier_id' => $identifier->id,
            'type' => ProfileType::AppStore,
        ]);
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `php artisan test --filter=ProfileTest`
Expected: FAIL — `Class "App\Models\Profile" not found`.

- [ ] **Step 3: Create the migration**

`database/migrations/2026_07_14_090200_create_profiles_table.php`:

```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('profiles', function (Blueprint $table) {
            $table->id();
            $table->foreignId('app_identifier_id')->constrained()->cascadeOnDelete();
            $table->string('type');           // ProfileType enum
            $table->string('name')->nullable();
            $table->timestamps();
            $table->unique(['app_identifier_id', 'type']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('profiles');
    }
};
```

- [ ] **Step 4: Create the model**

`app/Models/Profile.php`:

```php
<?php

namespace App\Models;

use App\Enums\ProfileType;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['app_identifier_id', 'type', 'name'])]
class Profile extends Model
{
    use HasFactory;

    public function appIdentifier(): BelongsTo
    {
        return $this->belongsTo(AppIdentifier::class);
    }

    protected function casts(): array
    {
        return [
            'type' => ProfileType::class,
        ];
    }
}
```

- [ ] **Step 5: Create the factory**

`database/factories/ProfileFactory.php`:

```php
<?php

namespace Database\Factories;

use App\Enums\ProfileType;
use App\Models\AppIdentifier;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<\App\Models\Profile>
 */
class ProfileFactory extends Factory
{
    public function definition(): array
    {
        return [
            'app_identifier_id' => AppIdentifier::factory(),
            'type' => ProfileType::AppStore,
            'name' => fake()->words(2, true),
        ];
    }
}
```

- [ ] **Step 6: Run test to verify it passes**

Run: `php artisan test --filter=ProfileTest`
Expected: PASS.

- [ ] **Step 7: Commit**

```bash
git add database/migrations/2026_07_14_090200_create_profiles_table.php app/Models/Profile.php database/factories/ProfileFactory.php tests/Feature/ProfileTest.php
git commit -m "feat: add Profile model with type enum and app identifier relation"
```

---

### Task 5: Credential model, migrations (credentials + credentialables), factory, and media collection

**Files:**
- Create: `database/migrations/2026_07_14_090300_create_credentials_table.php`
- Create: `database/migrations/2026_07_14_090400_create_credentialables_table.php`
- Create: `app/Models/Credential.php`
- Create: `database/factories/CredentialFactory.php`
- Test: `tests/Feature/CredentialTest.php`

**Interfaces:**
- Consumes: `App\Models\Team`, `App\Enums\CredentialType`, Spatie `HasMedia`/`InteractsWithMedia`.
- Produces:
  - `App\Models\Credential` implementing `HasMedia`, with `team(): BelongsTo`, casts `type => CredentialType::class`, `payload => 'encrypted:array'`, `expires_at => 'datetime'`, and a single-file media collection `file`. Fillable: `team_id`, `type`, `name`, `payload`, `expires_at`. (Morph relations `appIdentifiers()` / `profiles()` are added in Task 6.)
  - `Database\Factories\CredentialFactory` with `team_id` from `Team::factory()`, `type` defaulting to `CredentialType::IosCertificate`, `name`.

- [ ] **Step 1: Write the failing test**

Create `tests/Feature/CredentialTest.php`:

```php
<?php

namespace Tests\Feature;

use App\Enums\CredentialType;
use App\Models\Credential;
use App\Models\Team;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class CredentialTest extends TestCase
{
    use RefreshDatabase;

    public function test_belongs_to_team(): void
    {
        $credential = Credential::factory()->create();

        $this->assertInstanceOf(Team::class, $credential->team);
    }

    public function test_type_is_cast_to_enum(): void
    {
        $credential = Credential::factory()->create(['type' => CredentialType::ApnsKey]);

        $this->assertSame(CredentialType::ApnsKey, $credential->fresh()->type);
    }

    public function test_payload_round_trips_as_array_and_is_encrypted_at_rest(): void
    {
        $credential = Credential::factory()->create([
            'payload' => ['password' => 's3cret', 'key_id' => 'ABC123'],
        ]);

        $this->assertSame('s3cret', $credential->fresh()->payload['password']);

        $raw = DB::table('credentials')->where('id', $credential->id)->value('payload');
        $this->assertStringNotContainsString('s3cret', (string) $raw);
    }

    public function test_registers_file_media_collection_as_single_file(): void
    {
        $credential = Credential::factory()->create();
        $file = $credential->getRegisteredMediaCollections()->firstWhere('name', 'file');

        $this->assertNotNull($file);
        $this->assertTrue($file->singleFile);
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `php artisan test --filter=CredentialTest`
Expected: FAIL — `Class "App\Models\Credential" not found`.

- [ ] **Step 3: Create the credentials migration**

`database/migrations/2026_07_14_090300_create_credentials_table.php`:

```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('credentials', function (Blueprint $table) {
            $table->id();
            $table->foreignId('team_id')->constrained()->cascadeOnDelete();
            $table->string('type');                 // CredentialType enum
            $table->string('name');
            $table->text('payload')->nullable();    // encrypted:array cast
            $table->timestamp('expires_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('credentials');
    }
};
```

- [ ] **Step 4: Create the credentialables pivot migration**

`database/migrations/2026_07_14_090400_create_credentialables_table.php`:

```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('credentialables', function (Blueprint $table) {
            $table->id();
            $table->foreignId('credential_id')->constrained()->cascadeOnDelete();
            $table->morphs('credentialable'); // credentialable_id + credentialable_type
            $table->timestamps();
            $table->unique(
                ['credential_id', 'credentialable_id', 'credentialable_type'],
                'credentialable_unique'
            );
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('credentialables');
    }
};
```

- [ ] **Step 5: Create the model**

`app/Models/Credential.php`:

```php
<?php

namespace App\Models;

use App\Enums\CredentialType;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Spatie\MediaLibrary\HasMedia;
use Spatie\MediaLibrary\InteractsWithMedia;

#[Fillable(['team_id', 'type', 'name', 'payload', 'expires_at'])]
class Credential extends Model implements HasMedia
{
    use HasFactory, InteractsWithMedia;

    public function team(): BelongsTo
    {
        return $this->belongsTo(Team::class);
    }

    public function registerMediaCollections(): void
    {
        $this->addMediaCollection('file')->singleFile();
    }

    protected function casts(): array
    {
        return [
            'type' => CredentialType::class,
            'payload' => 'encrypted:array',
            'expires_at' => 'datetime',
        ];
    }
}
```

- [ ] **Step 6: Create the factory**

`database/factories/CredentialFactory.php`:

```php
<?php

namespace Database\Factories;

use App\Enums\CredentialType;
use App\Models\Team;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<\App\Models\Credential>
 */
class CredentialFactory extends Factory
{
    public function definition(): array
    {
        return [
            'team_id' => Team::factory(),
            'type' => CredentialType::IosCertificate,
            'name' => fake()->words(2, true),
            'payload' => null,
            'expires_at' => null,
        ];
    }
}
```

- [ ] **Step 7: Run test to verify it passes**

Run: `php artisan test --filter=CredentialTest`
Expected: PASS.

- [ ] **Step 8: Commit**

```bash
git add database/migrations/2026_07_14_090300_create_credentials_table.php database/migrations/2026_07_14_090400_create_credentialables_table.php app/Models/Credential.php database/factories/CredentialFactory.php tests/Feature/CredentialTest.php
git commit -m "feat: add Credential model with encrypted payload, media file, and pivot table"
```

---

### Task 6: Polymorphic sharing relations (Credential ⇄ AppIdentifier | Profile)

**Files:**
- Modify: `app/Models/Credential.php` (add `appIdentifiers()`, `profiles()` morphedByMany relations)
- Modify: `app/Models/AppIdentifier.php` (add `credentials()` morphToMany relation)
- Modify: `app/Models/Profile.php` (add `credentials()` morphToMany relation)
- Test: `tests/Feature/CredentialSharingTest.php`

**Interfaces:**
- Consumes: `Credential`, `AppIdentifier`, `Profile` (all from prior tasks), the `credentialables` pivot table.
- Produces:
  - `Credential::appIdentifiers(): MorphToMany` — `morphedByMany(AppIdentifier::class, 'credentialable')`
  - `Credential::profiles(): MorphToMany` — `morphedByMany(Profile::class, 'credentialable')`
  - `AppIdentifier::credentials(): MorphToMany` — `morphToMany(Credential::class, 'credentialable')`
  - `Profile::credentials(): MorphToMany` — `morphToMany(Credential::class, 'credentialable')`

- [ ] **Step 1: Write the failing test**

Create `tests/Feature/CredentialSharingTest.php`:

```php
<?php

namespace Tests\Feature;

use App\Enums\CredentialType;
use App\Models\AppIdentifier;
use App\Models\Credential;
use App\Models\Profile;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CredentialSharingTest extends TestCase
{
    use RefreshDatabase;

    public function test_credential_can_be_shared_across_multiple_app_identifiers(): void
    {
        $credential = Credential::factory()->create(['type' => CredentialType::IosCertificate]);
        $first = AppIdentifier::factory()->create();
        $second = AppIdentifier::factory()->create();

        $credential->appIdentifiers()->attach([$first->id, $second->id]);

        $this->assertCount(2, $credential->appIdentifiers);
        $this->assertTrue($first->credentials->contains($credential));
        $this->assertTrue($second->credentials->contains($credential));
    }

    public function test_provisioning_profile_credential_binds_to_a_profile(): void
    {
        $credential = Credential::factory()->create(['type' => CredentialType::ProvisioningProfile]);
        $profile = Profile::factory()->create();

        $credential->profiles()->attach($profile);

        $this->assertTrue($credential->profiles->contains($profile));
        $this->assertTrue($profile->credentials->contains($credential));
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `php artisan test --filter=CredentialSharingTest`
Expected: FAIL — `Call to undefined method App\Models\Credential::appIdentifiers()`.

- [ ] **Step 3: Add morphedByMany relations to Credential**

In `app/Models/Credential.php`, add the import and the two relation methods (place after `team()`):

Add to the `use` block:

```php
use Illuminate\Database\Eloquent\Relations\MorphToMany;
```

Add methods:

```php
    public function appIdentifiers(): MorphToMany
    {
        return $this->morphedByMany(AppIdentifier::class, 'credentialable');
    }

    public function profiles(): MorphToMany
    {
        return $this->morphedByMany(Profile::class, 'credentialable');
    }
```

- [ ] **Step 4: Add morphToMany relation to AppIdentifier**

In `app/Models/AppIdentifier.php`, add to the `use` block:

```php
use Illuminate\Database\Eloquent\Relations\MorphToMany;
```

Add method (after `profiles()`):

```php
    public function credentials(): MorphToMany
    {
        return $this->morphToMany(Credential::class, 'credentialable');
    }
```

- [ ] **Step 5: Add morphToMany relation to Profile**

In `app/Models/Profile.php`, add to the `use` block:

```php
use Illuminate\Database\Eloquent\Relations\MorphToMany;
```

Add method (after `appIdentifier()`):

```php
    public function credentials(): MorphToMany
    {
        return $this->morphToMany(Credential::class, 'credentialable');
    }
```

- [ ] **Step 6: Run test to verify it passes**

Run: `php artisan test --filter=CredentialSharingTest`
Expected: PASS.

- [ ] **Step 7: Commit**

```bash
git add app/Models/Credential.php app/Models/AppIdentifier.php app/Models/Profile.php tests/Feature/CredentialSharingTest.php
git commit -m "feat: add polymorphic credential sharing relations"
```

---

### Task 7: Team relations to MobileApp and Credential

**Files:**
- Modify: `app/Models/Team.php` (add `mobileApps()` and `credentials()` relations)
- Test: `tests/Feature/TeamPlatformRelationsTest.php`

**Interfaces:**
- Consumes: `MobileApp`, `Credential`, existing `Team`.
- Produces:
  - `Team::mobileApps(): HasMany` — `hasMany(MobileApp::class)`
  - `Team::credentials(): HasMany` — `hasMany(Credential::class)`

- [ ] **Step 1: Write the failing test**

Create `tests/Feature/TeamPlatformRelationsTest.php`:

```php
<?php

namespace Tests\Feature;

use App\Models\Credential;
use App\Models\MobileApp;
use App\Models\Team;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class TeamPlatformRelationsTest extends TestCase
{
    use RefreshDatabase;

    public function test_team_has_many_mobile_apps(): void
    {
        $team = Team::factory()->create();
        MobileApp::factory()->count(2)->create(['team_id' => $team->id]);

        $this->assertCount(2, $team->mobileApps);
    }

    public function test_team_has_many_credentials(): void
    {
        $team = Team::factory()->create();
        Credential::factory()->count(3)->create(['team_id' => $team->id]);

        $this->assertCount(3, $team->credentials);
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `php artisan test --filter=TeamPlatformRelationsTest`
Expected: FAIL — `Call to undefined method App\Models\Team::mobileApps()`.

- [ ] **Step 3: Add relations to Team**

In `app/Models/Team.php`, add to the `use` block:

```php
use Illuminate\Database\Eloquent\Relations\HasMany;
```

Add methods (after `users()`):

```php
    public function mobileApps(): HasMany
    {
        return $this->hasMany(MobileApp::class);
    }

    public function credentials(): HasMany
    {
        return $this->hasMany(Credential::class);
    }
```

- [ ] **Step 4: Run test to verify it passes**

Run: `php artisan test --filter=TeamPlatformRelationsTest`
Expected: PASS.

- [ ] **Step 5: Run the full suite**

Run: `php artisan test`
Expected: all tests PASS (existing account/example tests plus the new platform tests).

- [ ] **Step 6: Commit**

```bash
git add app/Models/Team.php tests/Feature/TeamPlatformRelationsTest.php
git commit -m "feat: add team relations to mobile apps and credentials"
```

---

## Self-Review

**Spec coverage:**
- MobileApp (slug/name/description + icons via media) → Task 2 ✅
- AppIdentifier (platform, identifier) → Task 3 ✅
- Profile (type, belongs to AppIdentifier) → Task 4 ✅
- Credential (polymorphic single table, encrypted payload, file via media) → Task 5 ✅
- credentialables polymorphic shared pivot → Task 5 (migration) + Task 6 (relations) ✅
- Mixed attach level (AppIdentifier vs Profile) → Task 6 relations + sharing test ✅
- Team-owned ownership → team_id FKs (Tasks 2, 5) + Team relations (Task 7) ✅
- Enums Platform / ProfileType / CredentialType → Task 1 ✅
- Spatie Media Library install → Task 0 ✅
- Attachment-rule/platform validation is explicitly out of scope (app layer) — not planned, matches spec ✅

**Placeholder scan:** No TBD/TODO; every code step contains complete code. ✅

**Type consistency:** Relation names (`mobileApp`, `appIdentifiers`, `profiles`, `credentials`, `mobileApps`), casts (`Platform`, `ProfileType`, `CredentialType`, `encrypted:array`), and morph name `credentialable` are consistent across tasks and match the `credentialables` table. Factory class names match model names for `::factory()` resolution. ✅

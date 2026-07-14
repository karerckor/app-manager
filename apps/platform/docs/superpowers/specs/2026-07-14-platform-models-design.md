# Platform Data Model — MobileApp, AppIdentifier, Profile, Credentials

**Date:** 2026-07-14
**Scope:** Migrations, Eloquent models, enums, and relationships for the `platform` app. No controllers, UI, or business logic beyond model-layer casts and relations.

## Stack

- Laravel 13 / PHP 8.3
- Attribute-based model config (`#[Fillable]`, `#[Hidden]`) as used by existing `User`/`Team`
- Existing Team-based multi-tenancy (`teams`, `team_user`, `users.current_team_id`)
- **Spatie Media Library** (`spatie/laravel-medialibrary`) for all file storage — not yet installed

## Decisions

| Topic | Decision |
|---|---|
| Identifiers | UUID primary keys for every application model; UUID foreign and morph keys throughout |
| Credential representation | Single **polymorphic `credentials` table** (`type` enum + encrypted `payload`) |
| Credential attachment | **Mixed** — signing credentials attach to a build `Profile`; service credentials attach to `AppIdentifier` |
| Sharing | Credentials are shareable many-to-many via one **polymorphic pivot** (`credentialables`, Laravel `morphToMany`) |
| Profile | **First-class table** under `AppIdentifier` |
| Ownership | **Team-owned**, shareable within the same team only |
| Files | **Spatie Media Library** collections for app icon, splash icon, and credential files |

## Entities & Relationships

```
Team (existing)
 └─< MobileApp                team_id, slug, name, description  (+ media: app_icon, splash_icon)
       └─< AppIdentifier      mobile_app_id, platform(ios|android), identifier, name, version, version_code(android only)
             └─< Profile      app_identifier_id, type, name

Team
 └─< Credential               team_id, type, name, payload(encrypted), expires_at  (+ media: file)

Credential  ⇄  { AppIdentifier | Profile }   via polymorphic pivot `credentialables`
```

### Attachment rules

Enforced in the app layer (form requests / service), **not** the schema, consistent with the polymorphic table.

| Credential type | Attaches to | Shareable |
|---|---|---|
| `ios_certificate` (App Store / development / Ad Hoc / enterprise) | **Profile** | ✅ |
| `provisioning_profile` | **Profile** | ✅ |
| `apns_key` | AppIdentifier | ✅ |
| `apple_api_key` (App Store Connect / TestFlight) | AppIdentifier | ✅ |
| `android_keystore` (production / preview / development) | **Profile** | ✅ |
| `firebase_config` (Firebase HTTP v1) | AppIdentifier | ✅ |
| `google_play_service_account` | AppIdentifier | ✅ |

Everything shares through the same pivot. Signing material is build-scoped because the Profile determines the distribution channel and output artifact (`.ipa`, `.apk`, or `.aab`); service credentials remain reusable at the identifier level.

## Migrations

**1. `create_mobile_apps_table`**
```php
Schema::create('mobile_apps', function (Blueprint $table) {
    $table->uuid('id')->primary();
    $table->foreignUuid('team_id')->constrained()->cascadeOnDelete();
    $table->string('slug');
    $table->string('name');
    $table->text('description')->nullable();
    $table->timestamps();
    $table->unique(['team_id', 'slug']);
});
```

**2. `create_app_identifiers_table`**
```php
Schema::create('app_identifiers', function (Blueprint $table) {
    $table->uuid('id')->primary();
    $table->foreignUuid('mobile_app_id')->constrained()->cascadeOnDelete();
    $table->string('platform');          // ios | android  (Platform enum cast)
    $table->string('identifier');        // bundle id / package name
    $table->string('name')->nullable();
    $table->string('version')->nullable();
    $table->unsignedBigInteger('version_code')->nullable(); // Android only
    $table->timestamps();
    $table->unique(['mobile_app_id', 'platform', 'identifier']);
});
```

**3. `create_profiles_table`**
```php
Schema::create('profiles', function (Blueprint $table) {
    $table->uuid('id')->primary();
    $table->foreignUuid('app_identifier_id')->constrained()->cascadeOnDelete();
    $table->string('type');              // ProfileType enum
    $table->string('name')->nullable();
    $table->timestamps();
    $table->unique(['app_identifier_id', 'type']);   // one profile per distribution type per identifier
});
```

**4. `create_credentials_table`**
```php
Schema::create('credentials', function (Blueprint $table) {
    $table->uuid('id')->primary();
    $table->foreignUuid('team_id')->constrained()->cascadeOnDelete();
    $table->string('type');              // CredentialType enum
    $table->string('name');
    $table->text('payload')->nullable(); // encrypted cast: passwords, key_id, issuer_id, alias, etc.
    $table->timestamp('expires_at')->nullable();
    $table->timestamps();
});
```

**5. `create_credentialables_table`** (polymorphic shared pivot)
```php
Schema::create('credentialables', function (Blueprint $table) {
    $table->id();
    $table->foreignUuid('credential_id')->constrained()->cascadeOnDelete();
    $table->uuidMorphs('credentialable'); // credentialable_id + credentialable_type → AppIdentifier | Profile
    $table->timestamps();
    $table->unique(['credential_id', 'credentialable_id', 'credentialable_type'], 'credentialable_unique');
});
```

**6. Spatie `media` table** — `php artisan vendor:publish --tag=medialibrary-migrations` (creates the polymorphic `media` table shared by `MobileApp` and `Credential`).

## Enums (`app/Enums/`)

```php
enum Platform: string {
    case Ios = 'ios';
    case Android = 'android';
}

enum ProfileType: string {
    // iOS
    case AppStore = 'appstore';
    case Development = 'development';   // reused by Android
    case Enterprise = 'enterprise';
    case AdHoc = 'adhoc';
    // Android
    case Production = 'production';
    case Preview = 'preview';
}

enum CredentialType: string {
    case IosCertificate = 'ios_certificate';
    case ProvisioningProfile = 'provisioning_profile';
    case ApnsKey = 'apns_key';
    case AppleApiKey = 'apple_api_key';               // App Store Connect / TestFlight
    case AndroidKeystore = 'android_keystore';
    case FirebaseConfig = 'firebase_config';          // Firebase HTTP v1
    case GooglePlayServiceAccount = 'google_play_service_account';
}
```

`ProfileType` holds 6 distinct values (Android `development` reuses the iOS case). The `platform` on the owning `AppIdentifier` constrains which values are valid — enforced in the app layer.

## Models & Relationships

| Model | Implements / Traits | Relations & casts |
|---|---|---|
| `User` (existing) | `HasUuids` | UUID primary key; UUID current-team, session, and Sanctum morph references |
| `Team` (existing) | `HasUuids` | add `hasMany(MobileApp)`, `hasMany(Credential)` |
| `MobileApp` | `HasUuids`, `HasMedia`, `InteractsWithMedia` | `belongsTo(Team)`, `hasMany(AppIdentifier)`. Media collections `app_icon`, `splash_icon` (single-file). `#[Fillable(['team_id','slug','name','description'])]` |
| `AppIdentifier` | `HasUuids` | `belongsTo(MobileApp)`, `hasMany(Profile)`, `morphToMany(Credential, 'credentialable')`. Casts `platform → Platform`, `version_code → integer`. `#[Fillable(['mobile_app_id','platform','identifier','name','version','version_code'])]` |
| `Profile` | `HasUuids` | `belongsTo(AppIdentifier)`, `morphToMany(Credential, 'credentialable')`. Cast `type → ProfileType`. `#[Fillable(['app_identifier_id','type','name'])]` |
| `Credential` | `HasUuids`, `HasMedia`, `InteractsWithMedia` | `belongsTo(Team)`, `morphedByMany(AppIdentifier, 'credentialable')`, `morphedByMany(Profile, 'credentialable')`. Casts `type → CredentialType`, `payload → 'encrypted:array'`, `expires_at → 'datetime'`. Media collection `file` (single-file). `#[Fillable(['team_id','type','name','payload','expires_at'])]` |

Model config follows the existing attribute-based style (`#[Fillable]`, `#[Hidden]`). Application models use Laravel's `HasUuids` trait. Media single-file collections use `->singleFile()` in `registerMediaCollections()`.

## Out of scope

- Controllers, requests, policies, UI (Inertia/Svelte pages)
- App-layer validation of attachment/platform-compatibility rules (design notes only)
- Optional provisioning-profile → certificate reference (can live in `payload` if needed later)
- Seeders / factories beyond what implementation requires

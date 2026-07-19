# Tags System Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Add colored tags (JSON 2D array of `[text, hexColor?]` tuples) to `MobileApp`, `BuildRecord`, `Credential`, and `Artifact`, via a shared `HasTags` trait, with strict validation, cross-database filtering, dual serialization, and a Svelte tag editor.

**Architecture:** A `TagRules` support class owns validation rules + normalization. A `HasTags` model trait adds the `tags` array cast, mutation helpers, and cross-DB query scopes. A single `TagsController` exposes one PATCH endpoint per model, reusing the tenancy + `DB::transaction` + webhook patterns already in `MobileAppController`. Tags are added to both serialization paths (API v1 resources + the inline Inertia arrays in `MobileAppController::show`). Two Svelte components (`TagList`, `TagEditor`) render and edit tags on `Pages/Apps/Show.svelte`.

**Tech Stack:** Laravel 13 (PHP 8.3), PostgreSQL (dev/prod) + SQLite (tests), Inertia + Svelte 5 (legacy `export let` / `on:click` syntax), PHPUnit.

## Global Constraints

- Every primary model uses UUID PKs (`HasUuids`) and a `team_id` (UUID) for multi-tenancy.
- Models declare fillable via the `#[Fillable([...])]` PHP attribute (NOT `$fillable`) and casts via a `casts()` **method** (NOT `$casts`).
- No FormRequest classes exist — validate inline with `$request->validate([...])`.
- Mutations wrap in `DB::transaction`; where an entity has an existing `*Updated` webhook event, fire it via the injected `WebhookEventRecorder`.
- Tenancy guard pattern: `abort_unless($request->user()->teams()->whereKey($team->id)->exists(), 403)` then `abort_unless($model->team_id === $team->id, 404)`.
- Stored tag shape is always 2-element tuples: `[["bug","#ff0000"],["urgent",null]]`. Hex stored lowercase. Text trimmed, max 50 chars, deduped case-insensitively.
- Svelte components use legacy Svelte-5 syntax to match the codebase: `export let` props, `on:click` / `on:submit|preventDefault` events.
- Run the PHP suite with `./vendor/bin/phpunit` from `apps/platform`. All commands below run from `/Users/igor/Projects/Personal/app-manager/apps/platform`.

---

## File Structure

- Create `app/Support/Tags/TagRules.php` — validation rules + normalization (single source of truth).
- Create `app/Models/Concerns/HasTags.php` — trait: cast, helpers, scopes.
- Create `database/migrations/2026_07_19_010000_add_tags_to_mobile_apps_table.php` (+ 3 siblings for `build_records`, `credentials`, `artifacts`).
- Modify `app/Models/MobileApp.php`, `BuildRecord.php`, `Credential.php`, `Artifact.php` — apply trait + add `'tags'` to `#[Fillable]`.
- Create `app/Http/Controllers/TagsController.php` — 4 thin actions + private `sync()`.
- Modify `routes/web.php` — 4 PATCH routes + import.
- Modify `app/Http/Resources/Api/V1/{MobileApp,BuildRecord,Credential,Artifact}Resource.php` — add `tags`.
- Modify `app/Http/Controllers/MobileAppController.php` — add `tags` + `tags_url`/route to the inline `show()` arrays and `credentialData()`.
- Create `resources/js/Components/TagList.svelte`, `resources/js/Components/TagEditor.svelte`.
- Modify `resources/js/Pages/Apps/Show.svelte` — import + render editors.
- Create `tests/Unit/TagRulesTest.php`, `tests/Feature/Tags/HasTagsTraitTest.php`, `tests/Feature/Tags/TagScopeTest.php`, `tests/Feature/Tags/TagsEndpointTest.php`, `tests/Feature/Tags/TagSerializationTest.php`.

---

## Task 1: TagRules support class

**Files:**
- Create: `app/Support/Tags/TagRules.php`
- Test: `tests/Unit/TagRulesTest.php`

**Interfaces:**
- Consumes: nothing.
- Produces:
  - `TagRules::rules(string $key = 'tags'): array` — validation rules array.
  - `TagRules::normalize(?array $tags): array` — returns `list<array{0: string, 1: string|null}>`.
  - Constants `TagRules::MAX_TEXT_LENGTH = 50`, `TagRules::HEX_REGEX`.

- [ ] **Step 1: Write the failing test**

```php
<?php

namespace Tests\Unit;

use App\Support\Tags\TagRules;
use Tests\TestCase;

class TagRulesTest extends TestCase
{
    public function test_normalize_trims_and_lowercases_hex(): void
    {
        $this->assertSame([['Bug', '#ff0000']], TagRules::normalize([['  Bug  ', '#FF0000']]));
    }

    public function test_normalize_drops_empty_text(): void
    {
        $this->assertSame([['ok', null]], TagRules::normalize([['   ', '#fff'], ['ok', null]]));
    }

    public function test_normalize_dedupes_case_insensitively_first_wins(): void
    {
        $this->assertSame([['Bug', '#111111']], TagRules::normalize([['Bug', '#111111'], ['bug', '#222222']]));
    }

    public function test_normalize_coerces_missing_color_to_null(): void
    {
        $this->assertSame([['urgent', null]], TagRules::normalize([['urgent']]));
    }

    public function test_normalize_handles_null_input(): void
    {
        $this->assertSame([], TagRules::normalize(null));
    }

    public function test_rules_shape(): void
    {
        $rules = TagRules::rules('tags');
        $this->assertArrayHasKey('tags', $rules);
        $this->assertArrayHasKey('tags.*', $rules);
        $this->assertArrayHasKey('tags.*.0', $rules);
        $this->assertArrayHasKey('tags.*.1', $rules);
        $this->assertContains('required', $rules['tags.*.0']);
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `./vendor/bin/phpunit tests/Unit/TagRulesTest.php`
Expected: FAIL — `Class "App\Support\Tags\TagRules" not found`.

- [ ] **Step 3: Write minimal implementation**

```php
<?php

namespace App\Support\Tags;

class TagRules
{
    public const MAX_TEXT_LENGTH = 50;

    public const HEX_REGEX = '/^#([0-9a-fA-F]{3}|[0-9a-fA-F]{6})$/';

    /**
     * Validation rules for a tags field posted as a 2D array of [text, hexColor?] tuples.
     *
     * @return array<string, array<int, string>>
     */
    public static function rules(string $key = 'tags'): array
    {
        return [
            $key => ['nullable', 'array'],
            "{$key}.*" => ['array', 'min:1', 'max:2'],
            "{$key}.*.0" => ['required', 'string', 'max:'.self::MAX_TEXT_LENGTH],
            "{$key}.*.1" => ['nullable', 'string', 'regex:'.self::HEX_REGEX],
        ];
    }

    /**
     * Normalize raw tuples: trim text, drop empty text, dedupe by text
     * (case-insensitive, first wins), lowercase hex, coerce to 2-element tuples.
     *
     * @param  array<int, mixed>|null  $tags
     * @return array<int, array{0: string, 1: string|null}>
     */
    public static function normalize(?array $tags): array
    {
        $normalized = [];
        $seen = [];

        foreach ($tags ?? [] as $tag) {
            if (! is_array($tag)) {
                continue;
            }

            $text = is_string($tag[0] ?? null) ? trim($tag[0]) : '';

            if ($text === '') {
                continue;
            }

            $key = mb_strtolower($text);

            if (isset($seen[$key])) {
                continue;
            }

            $rawColor = $tag[1] ?? null;
            $color = is_string($rawColor) && $rawColor !== '' ? strtolower($rawColor) : null;

            $seen[$key] = true;
            $normalized[] = [$text, $color];
        }

        return $normalized;
    }
}
```

- [ ] **Step 4: Run test to verify it passes**

Run: `./vendor/bin/phpunit tests/Unit/TagRulesTest.php`
Expected: PASS (6 tests).

- [ ] **Step 5: Commit**

```bash
git add app/Support/Tags/TagRules.php tests/Unit/TagRulesTest.php
git commit -m "feat(tags): add TagRules validation and normalization"
```

---

## Task 2: HasTags trait, migrations, model wiring

**Files:**
- Create: `app/Models/Concerns/HasTags.php`
- Create: `database/migrations/2026_07_19_010000_add_tags_to_mobile_apps_table.php`
- Create: `database/migrations/2026_07_19_010100_add_tags_to_build_records_table.php`
- Create: `database/migrations/2026_07_19_010200_add_tags_to_credentials_table.php`
- Create: `database/migrations/2026_07_19_010300_add_tags_to_artifacts_table.php`
- Modify: `app/Models/MobileApp.php`, `app/Models/BuildRecord.php`, `app/Models/Credential.php`, `app/Models/Artifact.php`
- Test: `tests/Feature/Tags/HasTagsTraitTest.php`

**Interfaces:**
- Consumes: `TagRules::normalize()` from Task 1.
- Produces (on every model using the trait):
  - cast `tags => array` (via `initializeHasTags()`).
  - `tagList(): array`, `hasTag(string): bool`, `addTag(string, ?string): static`, `removeTag(string): static`, `syncTags(array): static`.
  - `scopeWhereTag(Builder, string)`, `scopeWhereAnyTag(Builder, array)` (implemented in Task 3; this task only adds the mutation/cast half).

- [ ] **Step 1: Write the failing test**

```php
<?php

namespace Tests\Feature\Tags;

use App\Models\MobileApp;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class HasTagsTraitTest extends TestCase
{
    use RefreshDatabase;

    public function test_tags_cast_to_array_and_persist(): void
    {
        $app = MobileApp::factory()->create();
        $app->syncTags([['bug', '#ff0000'], ['urgent', null]])->save();

        $this->assertSame([['bug', '#ff0000'], ['urgent', null]], $app->fresh()->tags);
    }

    public function test_add_and_remove_tag(): void
    {
        $app = MobileApp::factory()->create();
        $app->addTag('Bug', '#FF0000');
        $this->assertTrue($app->hasTag('bug'));
        $this->assertSame([['Bug', '#ff0000']], $app->tags);

        $app->removeTag('bug');
        $this->assertFalse($app->hasTag('bug'));
        $this->assertSame([], $app->tags);
    }

    public function test_sync_normalizes(): void
    {
        $app = MobileApp::factory()->create();
        $app->syncTags([['  Bug  ', '#ABCDEF'], ['bug', '#000000'], ['  ', null]]);

        $this->assertSame([['Bug', '#abcdef']], $app->tags);
    }

    public function test_null_tags_reads_as_empty_via_tag_list(): void
    {
        $app = MobileApp::factory()->create();
        $this->assertSame([], $app->tagList());
        $this->assertFalse($app->hasTag('anything'));
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `./vendor/bin/phpunit tests/Feature/Tags/HasTagsTraitTest.php`
Expected: FAIL — `Call to undefined method App\Models\MobileApp::syncTags()`.

- [ ] **Step 3a: Create the trait**

Create `app/Models/Concerns/HasTags.php`:

```php
<?php

namespace App\Models\Concerns;

use App\Support\Tags\TagRules;
use Illuminate\Database\Eloquent\Builder;

trait HasTags
{
    public function initializeHasTags(): void
    {
        $this->mergeCasts(['tags' => 'array']);
    }

    /**
     * @return array<int, array{0: string, 1: string|null}>
     */
    public function tagList(): array
    {
        return $this->tags ?? [];
    }

    public function hasTag(string $text): bool
    {
        $needle = mb_strtolower(trim($text));

        foreach ($this->tagList() as $tag) {
            if (mb_strtolower((string) ($tag[0] ?? '')) === $needle) {
                return true;
            }
        }

        return false;
    }

    public function addTag(string $text, ?string $color = null): static
    {
        $this->tags = TagRules::normalize([...$this->tagList(), [$text, $color]]);

        return $this;
    }

    public function removeTag(string $text): static
    {
        $needle = mb_strtolower(trim($text));

        $this->tags = array_values(array_filter(
            $this->tagList(),
            fn ($tag) => mb_strtolower((string) ($tag[0] ?? '')) !== $needle,
        ));

        return $this;
    }

    public function syncTags(array $tags): static
    {
        $this->tags = TagRules::normalize($tags);

        return $this;
    }

    public function scopeWhereTag(Builder $query, string $text): Builder
    {
        return $query->where(fn (Builder $inner) => $this->applyTagPredicate($inner, [$text], 'and'));
    }

    public function scopeWhereAnyTag(Builder $query, array $texts): Builder
    {
        return $query->where(fn (Builder $inner) => $this->applyTagPredicate($inner, $texts, 'or'));
    }

    protected function applyTagPredicate(Builder $query, array $texts, string $boolean): Builder
    {
        $driver = $query->getConnection()->getDriverName();
        $column = $query->getModel()->qualifyColumn('tags');

        foreach ($texts as $text) {
            $sql = $driver === 'pgsql'
                ? "EXISTS (SELECT 1 FROM jsonb_array_elements(COALESCE(({$column})::jsonb, '[]'::jsonb)) el WHERE el->>0 = ?)"
                : "EXISTS (SELECT 1 FROM json_each(COALESCE({$column}, '[]')) el WHERE json_extract(el.value, '\$[0]') = ?)";

            $query->whereRaw($sql, [$text], $boolean);
        }

        return $query;
    }
}
```

- [ ] **Step 3b: Create the four migrations**

`database/migrations/2026_07_19_010000_add_tags_to_mobile_apps_table.php`:

```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('mobile_apps', function (Blueprint $table) {
            $table->json('tags')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('mobile_apps', function (Blueprint $table) {
            $table->dropColumn('tags');
        });
    }
};
```

Create the three siblings identically, changing only the table name and filename:
- `2026_07_19_010100_add_tags_to_build_records_table.php` → `build_records`
- `2026_07_19_010200_add_tags_to_credentials_table.php` → `credentials`
- `2026_07_19_010300_add_tags_to_artifacts_table.php` → `artifacts`

- [ ] **Step 3c: Wire the trait into the four models**

In `app/Models/MobileApp.php`: add `use App\Models\Concerns\HasTags;` to the imports, add `HasTags` to the `use HasFactory, HasUuids, InteractsWithMedia;` line (→ `use HasFactory, HasUuids, HasTags, InteractsWithMedia;`), and add `'tags'` to the `#[Fillable([...])]` array (→ `#[Fillable(['team_id', 'slug', 'name', 'description', 'tags'])]`).

In `app/Models/BuildRecord.php`: add `use App\Models\Concerns\HasTags;`, change trait line to `use HasFactory, HasUuids, HasTags;`, and append `'tags',` to the `#[Fillable([...])]` list.

In `app/Models/Credential.php`: add `use App\Models\Concerns\HasTags;`, change trait line to `use HasFactory, HasUuids, HasTags, InteractsWithMedia;`, and add `'tags'` to `#[Fillable([...])]`.

In `app/Models/Artifact.php`: add `use App\Models\Concerns\HasTags;`, change trait line to `use HasFactory, HasUuids, HasTags, InteractsWithMedia;`, and add `'tags'` to `#[Fillable([...])]`.

- [ ] **Step 4: Run migrations then the test**

Run: `./vendor/bin/phpunit tests/Feature/Tags/HasTagsTraitTest.php`
Expected: PASS (4 tests). (`RefreshDatabase` runs the new migrations against the test DB automatically.)

- [ ] **Step 5: Commit**

```bash
git add app/Models/Concerns/HasTags.php database/migrations/2026_07_19_0103*.php database/migrations/2026_07_19_0102*.php database/migrations/2026_07_19_0101*.php database/migrations/2026_07_19_0100*.php app/Models/MobileApp.php app/Models/BuildRecord.php app/Models/Credential.php app/Models/Artifact.php tests/Feature/Tags/HasTagsTraitTest.php
git commit -m "feat(tags): add HasTags trait, tags columns, and model wiring"
```

---

## Task 3: Filtering scopes

**Files:**
- Modify: `app/Models/Concerns/HasTags.php` (scopes already written in Task 2 — this task proves them with tests; if Task 2 omitted them, add now)
- Test: `tests/Feature/Tags/TagScopeTest.php`

**Interfaces:**
- Consumes: `scopeWhereTag`, `scopeWhereAnyTag` from Task 2.
- Produces: nothing new — verifies cross-DB filtering.

- [ ] **Step 1: Write the failing test**

```php
<?php

namespace Tests\Feature\Tags;

use App\Models\MobileApp;
use App\Models\Team;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class TagScopeTest extends TestCase
{
    use RefreshDatabase;

    public function test_where_tag_matches_by_text_only(): void
    {
        $team = Team::factory()->create();
        $tagged = MobileApp::factory()->create(['team_id' => $team->id]);
        $tagged->syncTags([['release', '#00ff00']])->save();
        $other = MobileApp::factory()->create(['team_id' => $team->id]);
        $other->syncTags([['bug', null]])->save();
        $untagged = MobileApp::factory()->create(['team_id' => $team->id]);

        $ids = MobileApp::whereTag('release')->pluck('id');

        $this->assertTrue($ids->contains($tagged->id));
        $this->assertFalse($ids->contains($other->id));
        $this->assertFalse($ids->contains($untagged->id));
    }

    public function test_where_any_tag_matches_multiple(): void
    {
        $team = Team::factory()->create();
        $a = MobileApp::factory()->create(['team_id' => $team->id]);
        $a->syncTags([['bug', null]])->save();
        $b = MobileApp::factory()->create(['team_id' => $team->id]);
        $b->syncTags([['release', null]])->save();
        $c = MobileApp::factory()->create(['team_id' => $team->id]);
        $c->syncTags([['chore', null]])->save();

        $ids = MobileApp::whereAnyTag(['bug', 'release'])->pluck('id');

        $this->assertTrue($ids->contains($a->id));
        $this->assertTrue($ids->contains($b->id));
        $this->assertFalse($ids->contains($c->id));
    }

    public function test_where_tag_can_compose_with_other_constraints(): void
    {
        $team = Team::factory()->create();
        $match = MobileApp::factory()->create(['team_id' => $team->id]);
        $match->syncTags([['bug', null]])->save();
        $otherTeam = MobileApp::factory()->create(['team_id' => Team::factory()->create()->id]);
        $otherTeam->syncTags([['bug', null]])->save();

        $ids = MobileApp::where('team_id', $team->id)->whereTag('bug')->pluck('id');

        $this->assertTrue($ids->contains($match->id));
        $this->assertFalse($ids->contains($otherTeam->id));
    }
}
```

- [ ] **Step 2: Run test to verify it fails or passes**

Run: `./vendor/bin/phpunit tests/Feature/Tags/TagScopeTest.php`
Expected: PASS if Task 2 included the scopes. If it FAILS with `Call to undefined method ...::whereTag()`, the scope methods (`scopeWhereTag`, `scopeWhereAnyTag`, `applyTagPredicate`) from Task 2 Step 3a are missing — add them to `app/Models/Concerns/HasTags.php` and re-run.

- [ ] **Step 3: (only if failing) add the scopes**

Add the `scopeWhereTag`, `scopeWhereAnyTag`, and `applyTagPredicate` methods exactly as shown in Task 2 Step 3a.

- [ ] **Step 4: Run test to verify it passes**

Run: `./vendor/bin/phpunit tests/Feature/Tags/TagScopeTest.php`
Expected: PASS (3 tests).

- [ ] **Step 5: Commit**

```bash
git add app/Models/Concerns/HasTags.php tests/Feature/Tags/TagScopeTest.php
git commit -m "test(tags): cover whereTag/whereAnyTag filtering scopes"
```

---

## Task 4: TagsController + routes

**Files:**
- Create: `app/Http/Controllers/TagsController.php`
- Modify: `routes/web.php`
- Test: `tests/Feature/Tags/TagsEndpointTest.php`

**Interfaces:**
- Consumes: `TagRules::rules()`, `Model::syncTags()`, `WebhookEventRecorder`, `WebhookPayload`.
- Produces: named routes `apps.tags.update`, `apps.builds.tags.update`, `apps.builds.artifacts.tags.update`, `credentials.tags.update` (all under the `accounts.` prefix).

- [ ] **Step 1: Write the failing test**

```php
<?php

namespace Tests\Feature\Tags;

use App\Models\AppIdentifier;
use App\Models\Artifact;
use App\Models\BuildRecord;
use App\Models\Credential;
use App\Models\MobileApp;
use App\Models\Team;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class TagsEndpointTest extends TestCase
{
    use RefreshDatabase;

    /** @return array{0: User, 1: Team} */
    private function member(): array
    {
        $user = User::factory()->create();
        $team = Team::factory()->create(['owner_id' => $user->id]);
        $team->users()->attach($user, ['role' => 'owner']);
        $user->update(['current_team_id' => $team->id]);

        return [$user, $team];
    }

    public function test_updates_app_tags(): void
    {
        [$user, $team] = $this->member();
        $app = MobileApp::factory()->create(['team_id' => $team->id]);

        $this->actingAs($user)
            ->patch("/accounts/{$team->slug}/apps/{$app->slug}/tags", [
                'tags' => [['bug', '#FF0000'], ['urgent', null]],
            ])
            ->assertRedirect();

        $this->assertSame([['bug', '#ff0000'], ['urgent', null]], $app->fresh()->tags);
    }

    public function test_updates_credential_tags(): void
    {
        [$user, $team] = $this->member();
        $credential = Credential::factory()->create(['team_id' => $team->id]);

        $this->actingAs($user)
            ->patch("/accounts/{$team->slug}/credentials/{$credential->id}/tags", [
                'tags' => [['prod', '#00ff00']],
            ])
            ->assertRedirect();

        $this->assertSame([['prod', '#00ff00']], $credential->fresh()->tags);
    }

    public function test_updates_build_and_artifact_tags(): void
    {
        [$user, $team] = $this->member();
        $app = MobileApp::factory()->create(['team_id' => $team->id]);
        $identifier = AppIdentifier::factory()->create(['mobile_app_id' => $app->id]);
        $build = BuildRecord::factory()->create([
            'team_id' => $team->id,
            'mobile_app_id' => $app->id,
            'app_identifier_id' => $identifier->id,
        ]);
        $artifact = Artifact::factory()->for($build)->create(['team_id' => $team->id]);

        $this->actingAs($user)
            ->patch("/accounts/{$team->slug}/apps/{$app->slug}/builds/{$build->id}/tags", [
                'tags' => [['nightly', null]],
            ])->assertRedirect();
        $this->assertSame([['nightly', null]], $build->fresh()->tags);

        $this->actingAs($user)
            ->patch("/accounts/{$team->slug}/apps/{$app->slug}/builds/{$build->id}/artifacts/{$artifact->id}/tags", [
                'tags' => [['signed', '#123abc']],
            ])->assertRedirect();
        $this->assertSame([['signed', '#123abc']], $artifact->fresh()->tags);
    }

    public function test_rejects_invalid_hex(): void
    {
        [$user, $team] = $this->member();
        $app = MobileApp::factory()->create(['team_id' => $team->id]);

        $this->actingAs($user)
            ->patch("/accounts/{$team->slug}/apps/{$app->slug}/tags", [
                'tags' => [['bug', 'red']],
            ])
            ->assertSessionHasErrors('tags.0.1');

        $this->assertSame([], $app->fresh()->tagList());
    }

    public function test_rejects_empty_text(): void
    {
        [$user, $team] = $this->member();
        $app = MobileApp::factory()->create(['team_id' => $team->id]);

        $this->actingAs($user)
            ->patch("/accounts/{$team->slug}/apps/{$app->slug}/tags", [
                'tags' => [['', '#ffffff']],
            ])
            ->assertSessionHasErrors('tags.0.0');
    }

    public function test_cannot_tag_app_from_another_team(): void
    {
        [$user, $team] = $this->member();
        $foreignApp = MobileApp::factory()->create(['team_id' => Team::factory()->create()->id]);

        $this->actingAs($user)
            ->patch("/accounts/{$team->slug}/apps/{$foreignApp->slug}/tags", [
                'tags' => [['x', null]],
            ])
            ->assertNotFound();
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `./vendor/bin/phpunit tests/Feature/Tags/TagsEndpointTest.php`
Expected: FAIL — route not defined (`Symfony\...\RouteNotFoundException` or 404 on all).

- [ ] **Step 3a: Create the controller**

Create `app/Http/Controllers/TagsController.php`:

```php
<?php

namespace App\Http\Controllers;

use App\Enums\WebhookEventType;
use App\Integrations\Webhooks\WebhookEventRecorder;
use App\Integrations\Webhooks\WebhookPayload;
use App\Models\Artifact;
use App\Models\BuildRecord;
use App\Models\Credential;
use App\Models\MobileApp;
use App\Models\Team;
use App\Support\Tags\TagRules;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class TagsController extends Controller
{
    public function __construct(private readonly WebhookEventRecorder $webhookEvents) {}

    public function updateApp(Request $request, Team $team, MobileApp $mobileApp): RedirectResponse
    {
        $this->authorizeTeam($request, $team);
        abort_unless($mobileApp->team_id === $team->id, 404);

        $this->sync($request, $team, $mobileApp);

        return back()->with('status', 'Tags updated.');
    }

    public function updateCredential(Request $request, Team $team, Credential $credential): RedirectResponse
    {
        $this->authorizeTeam($request, $team);
        abort_unless($credential->team_id === $team->id, 404);

        $this->sync($request, $team, $credential);

        return back()->with('status', 'Tags updated.');
    }

    public function updateBuild(Request $request, Team $team, MobileApp $mobileApp, BuildRecord $buildRecord): RedirectResponse
    {
        $this->authorizeTeam($request, $team);
        abort_unless($mobileApp->team_id === $team->id, 404);
        abort_unless($buildRecord->mobile_app_id === $mobileApp->id && $buildRecord->team_id === $team->id, 404);

        $this->sync($request, $team, $buildRecord);

        return back()->with('status', 'Tags updated.');
    }

    public function updateArtifact(Request $request, Team $team, MobileApp $mobileApp, BuildRecord $buildRecord, Artifact $artifact): RedirectResponse
    {
        $this->authorizeTeam($request, $team);
        abort_unless($mobileApp->team_id === $team->id, 404);
        abort_unless($buildRecord->mobile_app_id === $mobileApp->id && $buildRecord->team_id === $team->id, 404);
        abort_unless($artifact->build_record_id === $buildRecord->id && $artifact->team_id === $team->id, 404);

        $this->sync($request, $team, $artifact);

        return back()->with('status', 'Tags updated.');
    }

    private function sync(Request $request, Team $team, Model $model): void
    {
        $data = $request->validate(TagRules::rules());

        DB::transaction(function () use ($model, $team, $request, $data) {
            $model->syncTags($data['tags'] ?? [])->save();

            [$event, $payload] = match (true) {
                $model instanceof MobileApp => [WebhookEventType::MobileAppUpdated, WebhookPayload::mobileApp($model)],
                $model instanceof Credential => [WebhookEventType::CredentialUpdated, WebhookPayload::credential($model)],
                default => [null, null],
            };

            if ($event !== null) {
                $this->webhookEvents->record($team, $event, $model, $payload, $request->user());
            }
        });
    }

    private function authorizeTeam(Request $request, Team $team): void
    {
        abort_unless($request->user()->teams()->whereKey($team->id)->exists(), 403);
    }
}
```

- [ ] **Step 3b: Register the routes**

In `routes/web.php`, add the import near the other controller imports:

```php
use App\Http\Controllers\TagsController;
```

Inside the `Route::prefix('/accounts/{team:slug}')->scopeBindings()->name('accounts.')->group(...)` block, next to the existing `apps.*` routes (after the artifacts routes around line 93), add:

```php
Route::patch('/apps/{mobileApp:slug}/tags', [TagsController::class, 'updateApp'])
    ->name('apps.tags.update');
Route::patch('/apps/{mobileApp:slug}/builds/{buildRecord}/tags', [TagsController::class, 'updateBuild'])
    ->name('apps.builds.tags.update');
Route::patch('/apps/{mobileApp:slug}/builds/{buildRecord}/artifacts/{artifact}/tags', [TagsController::class, 'updateArtifact'])
    ->name('apps.builds.artifacts.tags.update');
Route::patch('/credentials/{credential}/tags', [TagsController::class, 'updateCredential'])
    ->name('credentials.tags.update');
```

- [ ] **Step 4: Run test to verify it passes**

Run: `./vendor/bin/phpunit tests/Feature/Tags/TagsEndpointTest.php`
Expected: PASS (6 tests).

- [ ] **Step 5: Commit**

```bash
git add app/Http/Controllers/TagsController.php routes/web.php tests/Feature/Tags/TagsEndpointTest.php
git commit -m "feat(tags): add TagsController and tag update routes"
```

---

## Task 5: Serialization (API resources + web Inertia arrays)

**Files:**
- Modify: `app/Http/Resources/Api/V1/MobileAppResource.php`, `BuildRecordResource.php`, `CredentialResource.php`, `ArtifactResource.php`
- Modify: `app/Http/Controllers/MobileAppController.php`
- Test: `tests/Feature/Tags/TagSerializationTest.php`

**Interfaces:**
- Consumes: `$model->tags` cast; the `*.tags.update` routes from Task 4.
- Produces: `tags` key on all 4 API resources; `tags` + tags-update URL on the inline Inertia arrays (`app.tags`, `app.urls.tags`, each credential's `tags`/`tags_url`, each build's `tags`/`tags_url`, each artifact's `tags`/`tags_url`).

- [ ] **Step 1: Write the failing test**

```php
<?php

namespace Tests\Feature\Tags;

use App\Http\Resources\Api\V1\ArtifactResource;
use App\Http\Resources\Api\V1\BuildRecordResource;
use App\Http\Resources\Api\V1\CredentialResource;
use App\Http\Resources\Api\V1\MobileAppResource;
use App\Models\AppIdentifier;
use App\Models\Artifact;
use App\Models\BuildRecord;
use App\Models\Credential;
use App\Models\MobileApp;
use App\Models\Team;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class TagSerializationTest extends TestCase
{
    use RefreshDatabase;

    public function test_api_resources_expose_tags(): void
    {
        $app = MobileApp::factory()->create();
        $app->syncTags([['a', null]])->save();
        $this->assertSame([['a', null]], (new MobileAppResource($app))->toArray(request()));
        $this->assertArrayHasKey('tags', (new MobileAppResource($app))->toArray(request()));

        $credential = Credential::factory()->create();
        $credential->syncTags([['b', '#ffffff']])->save();
        $this->assertSame([['b', '#ffffff']], (new CredentialResource($credential))->toArray(request())['tags']);

        $build = BuildRecord::factory()->create();
        $build->syncTags([['c', null]])->save();
        $this->assertSame([['c', null]], (new BuildRecordResource($build))->toArray(request())['tags']);

        $artifact = Artifact::factory()->create();
        $artifact->syncTags([['d', null]])->save();
        $this->assertSame([['d', null]], (new ArtifactResource($artifact))->toArray(request())['tags']);
    }

    public function test_show_page_exposes_tags_and_urls(): void
    {
        $user = User::factory()->create();
        $team = Team::factory()->create(['owner_id' => $user->id]);
        $team->users()->attach($user, ['role' => 'owner']);
        $user->update(['current_team_id' => $team->id]);

        $app = MobileApp::factory()->create(['team_id' => $team->id]);
        $app->syncTags([['flagship', '#abcdef']])->save();
        $identifier = AppIdentifier::factory()->create(['mobile_app_id' => $app->id]);
        $build = BuildRecord::factory()->create([
            'team_id' => $team->id,
            'mobile_app_id' => $app->id,
            'app_identifier_id' => $identifier->id,
        ]);
        $build->syncTags([['nightly', null]])->save();

        $this->actingAs($user)
            ->get("/accounts/{$team->slug}/apps/{$app->slug}")
            ->assertInertia(fn (Assert $page) => $page
                ->where('app.tags.0.0', 'flagship')
                ->has('app.urls.tags')
                ->where('builds.0.tags.0.0', 'nightly')
                ->has('builds.0.tags_url'));
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `./vendor/bin/phpunit tests/Feature/Tags/TagSerializationTest.php`
Expected: FAIL — `Undefined array key "tags"` / missing Inertia prop `app.urls.tags`.

- [ ] **Step 3a: Add `tags` to the four API resources**

In each of `MobileAppResource`, `BuildRecordResource`, `CredentialResource`, `ArtifactResource`, add this line to the array returned by `toArray()` (place it right after the `'name'`/`'slug'` line, before `'created_at'`):

```php
'tags' => $this->tags ?? [],
```

- [ ] **Step 3b: Add tags to the web `show()` arrays**

In `app/Http/Controllers/MobileAppController.php`, `show()`:

In the `'app' => [ ... ]` array, after `'description' => $mobileApp->description,` add:

```php
'tags' => $mobileApp->tags ?? [],
```

In the `'urls' => [ ... ]` sub-array (inside `'app'`), after `'update' => route('accounts.apps.update', ...),` add:

```php
'tags' => route('accounts.apps.tags.update', ['team' => $team, 'mobileApp' => $mobileApp]),
```

In the `builds` map (the `return [ ... ]` for each `$build`), after `'created_at' => $build->created_at,` add:

```php
'tags' => $build->tags ?? [],
'tags_url' => route('accounts.apps.builds.tags.update', [$team, $mobileApp, $build]),
```

In the nested `artifacts` map (the `return [ ... ]` for each `$artifact`), after `'metadata' => $artifact->metadata,` add:

```php
'tags' => $artifact->tags ?? [],
'tags_url' => route('accounts.apps.builds.artifacts.tags.update', [$team, $mobileApp, $build, $artifact]),
```

In `credentialData()`, in the returned array after `'name' => $credential->name,` add:

```php
'tags' => $credential->tags ?? [],
'tags_url' => route('accounts.credentials.tags.update', ['team' => $team, 'credential' => $credential]),
```

- [ ] **Step 4: Run test to verify it passes**

Run: `./vendor/bin/phpunit tests/Feature/Tags/TagSerializationTest.php`
Expected: PASS (2 tests).

- [ ] **Step 5: Commit**

```bash
git add app/Http/Resources/Api/V1 app/Http/Controllers/MobileAppController.php tests/Feature/Tags/TagSerializationTest.php
git commit -m "feat(tags): serialize tags in API resources and app show payload"
```

---

## Task 6: Frontend tag components + integration

**Files:**
- Create: `resources/js/Components/TagList.svelte`
- Create: `resources/js/Components/TagEditor.svelte`
- Modify: `resources/js/Pages/Apps/Show.svelte`

**Interfaces:**
- Consumes: `tags` arrays + `tags_url` / `app.urls.tags` from Task 5.
- Produces: reusable `<TagList>` (read-only) and `<TagEditor>` (edits via `router.patch`).

- [ ] **Step 1: Create `TagList.svelte`**

`resources/js/Components/TagList.svelte`:

```svelte
<script>
    export let tags = [];

    function contrast(hex) {
        const h = hex.replace('#', '');
        const full = h.length === 3 ? h.split('').map((c) => c + c).join('') : h;
        const r = parseInt(full.slice(0, 2), 16);
        const g = parseInt(full.slice(2, 4), 16);
        const b = parseInt(full.slice(4, 6), 16);
        const luminance = (0.299 * r + 0.587 * g + 0.114 * b) / 255;
        return luminance > 0.6 ? '#111827' : '#ffffff';
    }
</script>

{#if tags && tags.length}
    <span class="inline-flex flex-wrap gap-1">
        {#each tags as tag}
            <span
                class="rounded-full px-2 py-0.5 text-[10px] font-semibold"
                style="background-color: {tag[1] || '#e5e7eb'}; color: {tag[1] ? contrast(tag[1]) : '#374151'};"
            >
                {tag[0]}
            </span>
        {/each}
    </span>
{/if}
```

- [ ] **Step 2: Create `TagEditor.svelte`**

`resources/js/Components/TagEditor.svelte`:

```svelte
<script>
    import { router } from '@inertiajs/svelte';

    export let tags = [];
    export let url;
    export let disabled = false;

    let working = [];
    let text = '';
    let color = '#3b82f6';
    let useColor = false;
    let saving = false;

    $: working = (tags || []).map((t) => [t[0], t[1] ?? null]);

    function contrast(hex) {
        const h = hex.replace('#', '');
        const full = h.length === 3 ? h.split('').map((c) => c + c).join('') : h;
        const r = parseInt(full.slice(0, 2), 16);
        const g = parseInt(full.slice(2, 4), 16);
        const b = parseInt(full.slice(4, 6), 16);
        return (0.299 * r + 0.587 * g + 0.114 * b) / 255 > 0.6 ? '#111827' : '#ffffff';
    }

    function submit(next) {
        saving = true;
        router.patch(url, { tags: next }, {
            preserveScroll: true,
            onFinish: () => { saving = false; },
        });
    }

    function addTag() {
        const value = text.trim();
        if (!value) return;
        if (working.some((t) => t[0].toLowerCase() === value.toLowerCase())) {
            text = '';
            return;
        }
        const next = [...working, [value, useColor ? color : null]];
        text = '';
        submit(next);
    }

    function removeTag(index) {
        submit(working.filter((_, i) => i !== index));
    }
</script>

<div class="flex flex-wrap items-center gap-1.5">
    {#each working as tag, i}
        <span
            class="inline-flex items-center gap-1 rounded-full px-2 py-0.5 text-[10px] font-semibold"
            style="background-color: {tag[1] || '#e5e7eb'}; color: {tag[1] ? contrast(tag[1]) : '#374151'};"
        >
            {tag[0]}
            {#if !disabled}
                <button type="button" class="leading-none opacity-70 hover:opacity-100" aria-label="Remove tag" on:click={() => removeTag(i)}>×</button>
            {/if}
        </span>
    {/each}

    {#if !working.length}
        <span class="text-[10px] text-slate-400">No tags</span>
    {/if}

    {#if !disabled}
        <span class="inline-flex items-center gap-1">
            <input
                type="text"
                class="w-24 rounded border border-slate-200 px-2 py-0.5 text-[10px]"
                placeholder="Add tag"
                bind:value={text}
                on:keydown={(e) => e.key === 'Enter' && (e.preventDefault(), addTag())}
            />
            <input type="checkbox" bind:checked={useColor} aria-label="Use color" />
            <input type="color" bind:value={color} disabled={!useColor} class="h-5 w-5 border-0 bg-transparent p-0" />
            <button type="button" class="ghost px-2 py-0.5 text-[10px]" disabled={saving} on:click={addTag}>Add</button>
        </span>
    {/if}
</div>
```

- [ ] **Step 3: Integrate into `Show.svelte`**

In `resources/js/Pages/Apps/Show.svelte`, add the import alongside the existing imports at the top of the `<script>` block:

```js
import TagEditor from '../../Components/TagEditor.svelte';
```

Then render `<TagEditor>` at these four anchor points (place each inside the relevant entity's markup):

1. **App-level** — near the app header/edit button (around line 366, after the `openEdit` button block):
```svelte
<TagEditor tags={app.tags} url={app.urls.tags} />
```

2. **Per credential** — inside the credential row's actions cell (around line 456, within the credentials table `<td>`):
```svelte
<TagEditor tags={credential.tags} url={credential.tags_url} />
```

3. **Per build** — inside each build row (around line 495, near the build's action buttons):
```svelte
<TagEditor tags={build.tags} url={build.tags_url} />
```

4. **Per artifact** — inside each artifact row (around line 525, near the artifact delete button):
```svelte
<TagEditor tags={artifact.tags} url={artifact.tags_url} />
```

Verify the relative import path resolves: `Show.svelte` is at `resources/js/Pages/Apps/`, so `../../Components/TagEditor.svelte` reaches `resources/js/Components/`.

- [ ] **Step 4: Build the frontend to verify it compiles**

Run: `npm run build`
Expected: build succeeds with no Svelte/Vite errors; the four `<TagEditor>` usages compile.

- [ ] **Step 5: Commit**

```bash
git add resources/js/Components/TagList.svelte resources/js/Components/TagEditor.svelte resources/js/Pages/Apps/Show.svelte
git commit -m "feat(tags): add TagList/TagEditor components and wire into app show page"
```

---

## Task 7: Full suite verification

- [ ] **Step 1: Run the whole PHP suite**

Run: `./vendor/bin/phpunit`
Expected: PASS (all existing tests plus the 5 new tag test files green).

- [ ] **Step 2: Run the frontend build once more**

Run: `npm run build`
Expected: success.

- [ ] **Step 3: Commit any final fixes** (only if Steps 1–2 required changes)

```bash
git add -A
git commit -m "test(tags): fix issues surfaced by full suite run"
```

---

## Self-Review Notes

- **Spec coverage:** storage format (Task 2 migrations/cast), `HasTags` trait (Task 2), strict validation via `TagRules` (Task 1, enforced Task 4), cross-DB filtering scope (Task 2/3), single `TagsController` with tenancy + transaction + webhook-where-exists (Task 4), dual serialization (Task 5), Svelte `TagList`/`TagEditor` + integration (Task 6), tests incl. tenant isolation (Tasks 4–5). All spec sections mapped.
- **Webhook scope:** only `MobileApp` and `Credential` fire `*Updated` events (they exist in `WebhookEventType`); `BuildRecord`/`Artifact` have none, so the `match` returns `[null, null]` and no event fires — matches the spec's "skip where no event exists".
- **Type consistency:** `syncTags(array): static`, `tagList(): array`, `hasTag(string): bool` used identically across trait, controller, and tests. Tuple shape `[string, string|null]` consistent in PHP normalization and Svelte rendering (`tag[0]`, `tag[1]`).
- **SQLite JSON note:** `json_each`/`json_extract` require the SQLite JSON1 extension, which is bundled in modern SQLite (default in PHP's `pdo_sqlite`); `COALESCE(..., '[]')` guards null columns on both drivers.

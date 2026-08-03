# Flexible Tag Tuples Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Widen the tag tuple from a fixed `[text, color]` pair to a variable-length list carrying an optional whitelisted animation token, and add the two query scopes the filters plan depends on.

**Architecture:** `TagRules::normalize()` remains the single source of truth for tag shape and now compacts trailing `null`s instead of padding to two elements. A new `TagAnimation` enum owns the whitelist; the token-to-CSS-class mapping lives only in JavaScript because tags are never rendered server-side. The `App\Casts\Tags` cast normalizes on both read and write, so old two-element rows read back compacted with no data migration.

**Tech Stack:** Laravel 12 (PHP 8.3+), PHPUnit with `Tests\TestCase` + `RefreshDatabase`, Svelte 5 (legacy mode in `TagEditor`), Tailwind 4, Inertia.

**Spec:** `docs/superpowers/specs/2026-08-03-tags-flexible-shape-design.md`

## Global Constraints

- Run tests from `apps/platform`. Full suite: `php artisan test`. Single file: `php artisan test tests/Unit/TagRulesTest.php`. Single test: `php artisan test --filter test_name`.
- Canonical animation tokens are lowercase: `pulse`, `ping`, `flash`, `tada`, `heartbeat`. Input is matched case-insensitively so `heartBeat` is accepted.
- Compaction rule, applied in exactly one place (`TagRules::normalize()`):
  `animation !== null → [text, color, animation]` (color may be `null`); else `color !== null → [text, color]`; else `[text]`.
- No new migration and no data backfill. Any step that adds one is wrong.
- Supported drivers: PostgreSQL (dev/prod) and SQLite (tests). Raw JSON SQL must guard a `null` column with `COALESCE(..., '[]')`.
- `TagRules::MAX_TEXT_LENGTH` (50) and `TagRules::HEX_REGEX` are unchanged.
- Existing `tags` JSON columns on `mobile_apps`, `build_records`, `credentials`, `artifacts`, `notification_destinations` are unchanged.
- `apps/platform/database/seeders/DemoSeeder.php` is deliberately not touched — it passes two-element tuples, which stay valid input.

## File Structure

**Create:**
- `app/Enums/TagAnimation.php` — the whitelist and loose (case-insensitive) lookup.
- `resources/js/lib/tagAnimations.js` — token → `{ label, class }` map plus `animationClass()`. The only place a token becomes a CSS class.
- `tests/Unit/TagAnimationTest.php`

**Modify:**
- `app/Support/Tags/TagRules.php` — third validation rule; compaction and animation in `normalize()`.
- `app/Casts/Tags.php` — docblock only.
- `app/Models/Concerns/HasTags.php` — `addTag()` third parameter; `whereAllTags` and `whereTagLike` scopes; docblocks.
- `app/Http/Controllers/DashboardController.php` — replace its inlined tag-search SQL with `whereTagLike`.
- `app/Support/Apps/AppContext.php:206`, `app/Http/Controllers/AppBuildPageController.php:84`, `app/Support/Audit/AuditEntryPresenter.php:473-480` — stop truncating to two elements.
- `resources/css/app.css` — three `@keyframes` + three utility classes.
- `resources/js/Components/TagEditor.svelte` — animation picker and badge class.
- `resources/js/Pages/Apps/Builds/Index.svelte:41,112`, `Builds/Show.svelte:94`, `Identifiers/Show.svelte:175` — stop truncating to two elements.
- Existing tests asserting the padded shape (enumerated in Task 3).

---

### Task 1: `TagAnimation` enum

**Files:**
- Create: `app/Enums/TagAnimation.php`
- Test: `tests/Unit/TagAnimationTest.php`

**Interfaces:**
- Consumes: nothing.
- Produces: `TagAnimation::tryFromLoose(mixed $value): ?TagAnimation` and `TagAnimation::values(): list<string>`. Used by `TagRules` (Task 2) and `HasTags::addTag()` (Task 4).

- [ ] **Step 1: Write the failing test**

Create `tests/Unit/TagAnimationTest.php`:

```php
<?php

namespace Tests\Unit;

use App\Enums\TagAnimation;
use Tests\TestCase;

class TagAnimationTest extends TestCase
{
    public function test_values_are_the_five_lowercase_tokens(): void
    {
        $this->assertSame(['pulse', 'ping', 'flash', 'tada', 'heartbeat'], TagAnimation::values());
    }

    public function test_try_from_loose_is_case_insensitive(): void
    {
        $this->assertSame(TagAnimation::HeartBeat, TagAnimation::tryFromLoose('heartBeat'));
        $this->assertSame(TagAnimation::HeartBeat, TagAnimation::tryFromLoose('HEARTBEAT'));
        $this->assertSame(TagAnimation::Pulse, TagAnimation::tryFromLoose('pulse'));
    }

    public function test_try_from_loose_returns_null_for_unusable_input(): void
    {
        $this->assertNull(TagAnimation::tryFromLoose('bounce'));
        $this->assertNull(TagAnimation::tryFromLoose(''));
        $this->assertNull(TagAnimation::tryFromLoose(null));
        $this->assertNull(TagAnimation::tryFromLoose(123));
        $this->assertNull(TagAnimation::tryFromLoose(['pulse']));
    }

    public function test_try_from_loose_passes_through_an_enum_instance(): void
    {
        $this->assertSame(TagAnimation::Tada, TagAnimation::tryFromLoose(TagAnimation::Tada));
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `php artisan test tests/Unit/TagAnimationTest.php`
Expected: FAIL — `Class "App\Enums\TagAnimation" not found`.

- [ ] **Step 3: Write the implementation**

Create `app/Enums/TagAnimation.php`:

```php
<?php

namespace App\Enums;

enum TagAnimation: string
{
    case Pulse = 'pulse';
    case Ping = 'ping';
    case Flash = 'flash';
    case Tada = 'tada';
    case HeartBeat = 'heartbeat';

    /**
     * Resolve a value case-insensitively, so the animate.css spelling
     * "heartBeat" is accepted and canonicalized to "heartbeat".
     */
    public static function tryFromLoose(mixed $value): ?self
    {
        if ($value instanceof self) {
            return $value;
        }

        if (! is_string($value) || $value === '') {
            return null;
        }

        return self::tryFrom(mb_strtolower($value));
    }

    /**
     * @return list<string>
     */
    public static function values(): array
    {
        return array_map(fn (self $case) => $case->value, self::cases());
    }
}
```

- [ ] **Step 4: Run test to verify it passes**

Run: `php artisan test tests/Unit/TagAnimationTest.php`
Expected: PASS, 4 tests.

- [ ] **Step 5: Commit**

```bash
git add app/Enums/TagAnimation.php tests/Unit/TagAnimationTest.php
git commit -m "feat(tags): add the TagAnimation whitelist enum"
```

---

### Task 2: `TagRules` — third field and compaction

**Files:**
- Modify: `app/Support/Tags/TagRules.php`
- Test: `tests/Unit/TagRulesTest.php`

**Interfaces:**
- Consumes: `TagAnimation::tryFromLoose()`, `TagAnimation::values()` (Task 1).
- Produces: `TagRules::rules(string $key = 'tags'): array` now emitting a `"{$key}.*.2"` key with `"{$key}.*"` widened to `max:3`; `TagRules::normalize(?array $tags): list<array{0: string, 1?: string|null, 2?: string|null}>` returning compacted tuples. Every later task depends on this shape.

This task intentionally changes the stored shape, which breaks assertions in five existing test files. Task 3 repairs them. **The suite will be red between Task 2 and Task 3** — that is expected and is why they are separate commits.

- [ ] **Step 1: Write the failing tests**

Add to `tests/Unit/TagRulesTest.php`:

```php
    public function test_normalize_compacts_a_text_only_tag(): void
    {
        $this->assertSame([['urgent']], TagRules::normalize([['urgent']]));
        $this->assertSame([['urgent']], TagRules::normalize([['urgent', null]]));
        $this->assertSame([['urgent']], TagRules::normalize([['urgent', null, null]]));
    }

    public function test_normalize_keeps_a_color_only_tag_two_wide(): void
    {
        $this->assertSame([['urgent', '#abcdef']], TagRules::normalize([['urgent', '#ABCDEF', null]]));
    }

    public function test_normalize_keeps_all_three_fields(): void
    {
        $this->assertSame(
            [['onboarding', '#10b981', 'pulse']],
            TagRules::normalize([['onboarding', '#10B981', 'pulse']]),
        );
    }

    public function test_normalize_retains_the_null_color_placeholder_when_only_animation_is_set(): void
    {
        $this->assertSame([['onboarding', null, 'tada']], TagRules::normalize([['onboarding', null, 'tada']]));
    }

    public function test_normalize_canonicalizes_animation_case(): void
    {
        $this->assertSame([['beat', null, 'heartbeat']], TagRules::normalize([['beat', null, 'heartBeat']]));
    }

    public function test_normalize_drops_an_unknown_animation(): void
    {
        $this->assertSame([['x']], TagRules::normalize([['x', null, 'bounce']]));
        $this->assertSame([['x']], TagRules::normalize([['x', null, 42]]));
    }

    public function test_rules_validate_the_animation_field(): void
    {
        $rules = TagRules::rules('tags');

        $this->assertArrayHasKey('tags.*.2', $rules);
        $this->assertContains('nullable', $rules['tags.*.2']);
        $this->assertContains('max:3', $rules['tags.*']);
    }

    public function test_animation_rule_accepts_whitelisted_values_case_insensitively(): void
    {
        $validator = validator(['tags' => [['ok', null, 'heartBeat']]], TagRules::rules());

        $this->assertFalse($validator->fails());
    }

    public function test_animation_rule_rejects_unknown_values(): void
    {
        $validator = validator(['tags' => [['ok', null, 'bounce']]], TagRules::rules());

        $this->assertTrue($validator->fails());
        $this->assertArrayHasKey('tags.0.2', $validator->errors()->toArray());
    }

    public function test_rules_reject_a_four_element_tuple(): void
    {
        $validator = validator(['tags' => [['ok', '#fff', 'pulse', 'extra']]], TagRules::rules());

        $this->assertTrue($validator->fails());
        $this->assertArrayHasKey('tags.0', $validator->errors()->toArray());
    }
```

- [ ] **Step 2: Run the new tests to verify they fail**

Run: `php artisan test tests/Unit/TagRulesTest.php`
Expected: the ten new tests FAIL. `test_normalize_compacts_a_text_only_tag` fails with the actual value `[['urgent', null]]`; `test_rules_validate_the_animation_field` fails on the missing `tags.*.2` key.

- [ ] **Step 3: Rewrite `TagRules`**

Replace the whole body of `app/Support/Tags/TagRules.php`:

```php
<?php

namespace App\Support\Tags;

use App\Enums\TagAnimation;
use Closure;

class TagRules
{
    public const MAX_TEXT_LENGTH = 50;

    public const HEX_REGEX = '/^#([0-9a-fA-F]{3}|[0-9a-fA-F]{6})$/';

    /**
     * Validation rules for a tags field posted as a 2D array of
     * [text, hexColor?, animation?] tuples.
     *
     * @return array<string, array<int, mixed>>
     */
    public static function rules(string $key = 'tags'): array
    {
        return [
            $key => ['nullable', 'array'],
            "{$key}.*" => ['array', 'min:1', 'max:3'],
            "{$key}.*.0" => ['required', 'string', 'max:'.self::MAX_TEXT_LENGTH],
            "{$key}.*.1" => ['nullable', 'string', 'regex:'.self::HEX_REGEX],
            "{$key}.*.2" => ['nullable', 'string', self::animationRule()],
        ];
    }

    /**
     * Normalize raw tuples: trim text, drop empty text, dedupe by text
     * (case-insensitive, first wins), lowercase hex, canonicalize the animation
     * token, then compact trailing nulls.
     *
     * A tag carrying an animation but no color keeps the null placeholder at
     * index 1 — a positional tuple has no other way to express the gap.
     *
     * Legacy string entries are accepted so records written before tags became
     * tuples can still be read, edited, and saved in the canonical shape.
     *
     * @param  array<int, mixed>|null  $tags
     * @return list<array{0: string, 1?: string|null, 2?: string|null}>
     */
    public static function normalize(?array $tags): array
    {
        $normalized = [];
        $seen = [];

        foreach ($tags ?? [] as $tag) {
            if (! is_array($tag) && ! is_string($tag)) {
                continue;
            }

            $text = is_string($tag) ? trim($tag) : (is_string($tag[0] ?? null) ? trim($tag[0]) : '');

            if ($text === '') {
                continue;
            }

            $key = mb_strtolower($text);

            if (isset($seen[$key])) {
                continue;
            }

            $seen[$key] = true;

            $rawColor = is_array($tag) ? ($tag[1] ?? null) : null;
            $color = is_string($rawColor) && $rawColor !== '' ? strtolower($rawColor) : null;

            $animation = is_array($tag)
                ? TagAnimation::tryFromLoose($tag[2] ?? null)?->value
                : null;

            $normalized[] = match (true) {
                $animation !== null => [$text, $color, $animation],
                $color !== null => [$text, $color],
                default => [$text],
            };
        }

        return $normalized;
    }

    /**
     * The animation whitelist is checked through a closure rather than
     * Rule::enum, which is case-sensitive and would reject "heartBeat".
     */
    private static function animationRule(): Closure
    {
        return function (string $attribute, mixed $value, Closure $fail): void {
            if ($value === null || $value === '') {
                return;
            }

            if (TagAnimation::tryFromLoose($value) === null) {
                $fail('The :attribute must be one of: '.implode(', ', TagAnimation::values()).'.');
            }
        };
    }
}
```

- [ ] **Step 4: Run the file's tests to verify the new ones pass**

Run: `php artisan test tests/Unit/TagRulesTest.php`
Expected: the ten new tests PASS. Four pre-existing tests now FAIL because they assert the padded shape — `test_normalize_drops_empty_text`, `test_normalize_coerces_missing_color_to_null`, `test_normalize_coerces_non_string_color_to_null`, `test_normalize_accepts_legacy_string_tags_and_ignores_extra_elements`. Leave them failing; Task 3 fixes them.

- [ ] **Step 5: Commit**

```bash
git add app/Support/Tags/TagRules.php tests/Unit/TagRulesTest.php
git commit -m "feat(tags): accept an animation token and compact tag tuples"
```

---

### Task 3: Repair the assertions that asserted the padded shape

**Files:**
- Modify: `tests/Unit/TagRulesTest.php:17,27,51,56`
- Modify: `tests/Feature/Tags/HasTagsTraitTest.php:19,58,62`
- Modify: `tests/Feature/Tags/TagSerializationTest.php:28,37,41`
- Modify: `tests/Feature/Tags/TagsEndpointTest.php:43,91`
- Modify: `tests/Feature/Api/V1/BuildTagsApiTest.php:34,36`

**Interfaces:**
- Consumes: the compacted shape from Task 2.
- Produces: a green suite. No production code changes.

Only *expected* values change. Inputs written as `[['bug', null]]` stay as they are — they are still valid input, and leaving them proves the compaction happens on the way in.

`tests/Feature/Api/V1/BuildTagsApiTest.php` is currently untracked work-in-progress on this branch. If it is absent, skip that file and note it.

- [ ] **Step 1: Update the four `TagRulesTest` expectations**

```php
    public function test_normalize_drops_empty_text(): void
    {
        $this->assertSame([['ok']], TagRules::normalize([['   ', '#fff'], ['ok', null]]));
    }

    public function test_normalize_compacts_a_missing_color(): void
    {
        $this->assertSame([['urgent']], TagRules::normalize([['urgent']]));
    }

    public function test_normalize_compacts_a_non_string_color(): void
    {
        $this->assertSame([['ok']], TagRules::normalize([['ok', 123]]));
    }

    public function test_normalize_accepts_legacy_string_tags(): void
    {
        $this->assertSame([['plain'], ['ok', '#fff']], TagRules::normalize(['plain', ['ok', '#fff']]));
    }
```

The old `test_normalize_coerces_missing_color_to_null` and `test_normalize_coerces_non_string_color_to_null` are renamed as above — the behavior they describe no longer exists. The old `..._and_ignores_extra_elements` name and its `'extra'` third element are dropped: index 2 is now the animation field and is covered by `test_normalize_drops_an_unknown_animation` from Task 2.

- [ ] **Step 2: Update `HasTagsTraitTest`**

Line 19:

```php
        $this->assertSame([['bug', '#ff0000'], ['urgent']], $app->fresh()->tags);
```

Lines 58 and 62:

```php
        $this->assertSame([['demo'], ['ios']], $app->tags);

        $app->removeTag('demo')->save();

        $this->assertSame([['ios']], $app->fresh()->tags);
```

- [ ] **Step 3: Update `TagSerializationTest`**

Lines 28, 37, 41 — expected values only:

```php
        $this->assertSame([['a']], (new MobileAppResource($app))->toArray(request())['tags']);
        ...
        $this->assertSame([['c']], (new BuildRecordResource($build))->toArray(request())['tags']);
        ...
        $this->assertSame([['d']], (new ArtifactResource($artifact))->toArray(request())['tags']);
```

Line 33's `[['b', '#ffffff']]` is unchanged — it has a color.

- [ ] **Step 4: Update `TagsEndpointTest`**

Lines 43 and 91:

```php
        $this->assertSame([['bug', '#ff0000'], ['urgent']], $app->fresh()->tags);
        ...
        $this->assertSame([['nightly']], $build->fresh()->tags);
```

- [ ] **Step 5: Update `BuildTagsApiTest` if present**

Lines 34-37:

```php
        $this->assertSame([['ios', '#3b82f6'], ['1.0.0']], $response->json('data.tags'));
        $this->assertSame(
            [['ios', '#3b82f6'], ['1.0.0']],
            BuildRecord::findOrFail($response->json('data.id'))->tags,
        );
```

- [ ] **Step 6: Run the full suite**

Run: `php artisan test`
Expected: PASS. If anything outside the five files above fails on a tag shape, it is a call site missed by the spec — record it and fix it here.

- [ ] **Step 7: Commit**

Stage explicit paths, never `git add tests/`. This branch has unrelated
already-staged test changes (`tests/Feature/AppIdentifierPageTest.php`,
`tests/Feature/CredentialPresenterTest.php`); a directory-wide `add` would sweep
them into this commit. Check with `git status --short` first and adjust the list if
`BuildTagsApiTest.php` is absent.

```bash
git add tests/Unit/TagRulesTest.php \
        tests/Feature/Tags/HasTagsTraitTest.php \
        tests/Feature/Tags/TagSerializationTest.php \
        tests/Feature/Tags/TagsEndpointTest.php \
        tests/Feature/Api/V1/BuildTagsApiTest.php
git commit -m "test(tags): assert the compacted tuple shape"
```

---

### Task 4: `HasTags` — animation on `addTag`, and the two new scopes

**Files:**
- Modify: `app/Models/Concerns/HasTags.php`
- Modify: `app/Casts/Tags.php` (docblock only)
- Test: `tests/Feature/Tags/HasTagsTraitTest.php`, `tests/Feature/Tags/TagScopeTest.php`

**Interfaces:**
- Consumes: `TagAnimation` (Task 1), `TagRules::normalize()` (Task 2).
- Produces:
  - `addTag(string $text, ?string $color = null, TagAnimation|string|null $animation = null): static`
  - `scopeWhereAllTags(Builder $query, array $texts): Builder` → callable as `Model::whereAllTags(['a', 'b'])`
  - `scopeWhereTagLike(Builder $query, string $pattern): Builder` → callable as `Model::whereTagLike('%onb%')`. The `$pattern` argument includes its own `%` wildcards; the scope does not add them.

  Both scopes are consumed by `TagFilter` and `SearchFilter` in the filters plan.

- [ ] **Step 1: Write the failing tests**

Add to `tests/Feature/Tags/HasTagsTraitTest.php`:

```php
    public function test_add_tag_accepts_an_animation(): void
    {
        $app = MobileApp::factory()->create();
        $app->addTag('onboarding', '#10B981', 'heartBeat');

        $this->assertSame([['onboarding', '#10b981', 'heartbeat']], $app->tags);
    }

    public function test_add_tag_accepts_an_animation_enum_and_no_color(): void
    {
        $app = MobileApp::factory()->create();
        $app->addTag('beta', null, TagAnimation::Pulse);

        $this->assertSame([['beta', null, 'pulse']], $app->tags);
    }
```

Add `use App\Enums\TagAnimation;` to that file's imports.

Add to `tests/Feature/Tags/TagScopeTest.php`:

```php
    public function test_where_all_tags_requires_every_text(): void
    {
        $team = Team::factory()->create();
        $both = MobileApp::factory()->create(['team_id' => $team->id]);
        $both->syncTags([['beta', null], ['qa', '#fff']])->save();
        $one = MobileApp::factory()->create(['team_id' => $team->id]);
        $one->syncTags([['beta', null]])->save();

        $ids = MobileApp::whereAllTags(['beta', 'qa'])->pluck('id');

        $this->assertTrue($ids->contains($both->id));
        $this->assertFalse($ids->contains($one->id));
    }

    public function test_where_tag_like_matches_partial_text_case_insensitively(): void
    {
        $team = Team::factory()->create();
        $match = MobileApp::factory()->create(['team_id' => $team->id]);
        $match->syncTags([['Onboarding', null]])->save();
        $miss = MobileApp::factory()->create(['team_id' => $team->id]);
        $miss->syncTags([['release', null]])->save();

        $ids = MobileApp::whereTagLike('%onb%')->pluck('id');

        $this->assertTrue($ids->contains($match->id));
        $this->assertFalse($ids->contains($miss->id));
    }

    public function test_where_tag_like_ignores_color_and_animation(): void
    {
        $team = Team::factory()->create();
        $app = MobileApp::factory()->create(['team_id' => $team->id]);
        $app->syncTags([['beta', '#10b981', 'pulse']])->save();

        $this->assertFalse(MobileApp::whereTagLike('%10b981%')->pluck('id')->contains($app->id));
        $this->assertFalse(MobileApp::whereTagLike('%pulse%')->pluck('id')->contains($app->id));
        $this->assertTrue(MobileApp::whereTagLike('%bet%')->pluck('id')->contains($app->id));
    }

    public function test_new_scopes_tolerate_a_null_tags_column(): void
    {
        $team = Team::factory()->create();
        $untagged = MobileApp::factory()->create(['team_id' => $team->id]);

        $this->assertFalse(MobileApp::whereAllTags(['beta'])->pluck('id')->contains($untagged->id));
        $this->assertFalse(MobileApp::whereTagLike('%beta%')->pluck('id')->contains($untagged->id));
    }
```

- [ ] **Step 2: Run tests to verify they fail**

Run: `php artisan test tests/Feature/Tags/`
Expected: FAIL — `Call to undefined method App\Models\MobileApp::whereAllTags()`, and `addTag()` ignoring its third argument.

- [ ] **Step 3: Implement in `HasTags`**

Add `use App\Enums\TagAnimation;` to the imports. Replace `addTag()` and append the two scopes:

```php
    public function addTag(string $text, ?string $color = null, TagAnimation|string|null $animation = null): static
    {
        $this->tags = TagRules::normalize([
            ...$this->tagList(),
            [$text, $color, $animation instanceof TagAnimation ? $animation->value : $animation],
        ]);

        return $this;
    }
```

```php
    public function scopeWhereAllTags(Builder $query, array $texts): Builder
    {
        return $query->where(fn (Builder $inner) => $this->applyTagPredicate($inner, $texts, 'and'));
    }

    /**
     * Rows carrying a tag whose text partially matches, case-insensitively.
     * The pattern must already include its own % wildcards.
     */
    public function scopeWhereTagLike(Builder $query, string $pattern): Builder
    {
        $driver = $query->getConnection()->getDriverName();
        $column = $query->getModel()->qualifyColumn('tags');

        return match ($driver) {
            'pgsql' => $query->whereRaw(
                "EXISTS (SELECT 1 FROM jsonb_array_elements(COALESCE(({$column})::jsonb, '[]'::jsonb)) el WHERE el->>0 ILIKE ?)",
                [$pattern],
            ),
            'sqlite' => $query->whereRaw(
                "EXISTS (SELECT 1 FROM json_each(COALESCE({$column}, '[]')) el WHERE json_extract(el.value, '\$[0]') LIKE ? COLLATE NOCASE)",
                [$pattern],
            ),
            // Imprecise: matches the raw JSON, so a pattern can hit a color or
            // animation. Carried over from DashboardController and unreachable on
            // the drivers this project supports.
            default => $query->whereLike($column, $pattern),
        };
    }
```

Also update `tagList()`'s docblock return type to
`list<array{0: string, 1?: string|null, 2?: string|null}>`.

- [ ] **Step 4: Update the `Tags` cast docblock**

In `app/Casts/Tags.php`, change the `@implements` line to:

```php
/**
 * @implements CastsAttributes<list<array{0: string, 1?: string|null, 2?: string|null}>, array<int, mixed>|null>
 */
```

- [ ] **Step 5: Run tests to verify they pass**

Run: `php artisan test tests/Feature/Tags/ tests/Unit/TagRulesTest.php tests/Unit/TagAnimationTest.php`
Expected: PASS.

- [ ] **Step 6: Commit**

```bash
git add app/Models/Concerns/HasTags.php app/Casts/Tags.php tests/Feature/Tags/
git commit -m "feat(tags): add whereAllTags and whereTagLike scopes, animation on addTag"
```

---

### Task 5: Move `DashboardController`'s tag search onto `whereTagLike`

**Files:**
- Modify: `app/Http/Controllers/DashboardController.php:107-118`
- Test: `tests/Feature/` — locate the dashboard search test with
  `grep -rln "search" tests/Feature | xargs grep -ln "Dashboard"`; if none exists, create `tests/Feature/DashboardTagSearchTest.php`.

**Interfaces:**
- Consumes: `scopeWhereTagLike()` (Task 4).
- Produces: no new interface. Deletes the duplicated raw SQL.

- [ ] **Step 1: Write the failing test**

Create `tests/Feature/DashboardTagSearchTest.php` (or add the case to the existing dashboard test):

```php
<?php

namespace Tests\Feature;

use App\Models\MobileApp;
use App\Models\Team;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class DashboardTagSearchTest extends TestCase
{
    use RefreshDatabase;

    public function test_search_matches_an_app_only_through_its_tag_text(): void
    {
        $user = User::factory()->create();
        $team = Team::factory()->create(['owner_id' => $user->id]);
        $team->users()->attach($user, ['role' => 'owner']);
        $user->update(['current_team_id' => $team->id]);

        $tagged = MobileApp::factory()->create(['team_id' => $team->id, 'name' => 'Zulu']);
        $tagged->syncTags([['Onboarding', '#10b981', 'pulse']])->save();
        MobileApp::factory()->create(['team_id' => $team->id, 'name' => 'Yankee']);

        $this->actingAs($user)
            ->get("/accounts/{$team->slug}?search=onb")
            ->assertInertia(fn (Assert $page) => $page
                ->has('apps.data', 1)
                ->where('apps.data.0.name', 'Zulu'));
    }

    public function test_search_does_not_match_a_tag_color(): void
    {
        $user = User::factory()->create();
        $team = Team::factory()->create(['owner_id' => $user->id]);
        $team->users()->attach($user, ['role' => 'owner']);
        $user->update(['current_team_id' => $team->id]);

        $app = MobileApp::factory()->create(['team_id' => $team->id, 'name' => 'Zulu']);
        $app->syncTags([['beta', '#10b981']])->save();

        $this->actingAs($user)
            ->get("/accounts/{$team->slug}?search=10b981")
            ->assertInertia(fn (Assert $page) => $page->has('apps.data', 0));
    }
}
```

Confirm the dashboard route and the Inertia prop path first:
`grep -n "accounts" routes/web.php | grep -i dashboard` and check that
`DashboardController` renders `apps` as a paginator (it does — `'apps' => $apps`
with `->paginate(12)`, so the collection lives under `apps.data`).

- [ ] **Step 2: Run the test to verify the second case fails**

Run: `php artisan test tests/Feature/DashboardTagSearchTest.php`
Expected: `test_search_matches_an_app_only_through_its_tag_text` PASSES (current code already searches tag text); `test_search_does_not_match_a_tag_color` PASSES too on sqlite, because the current inlined SQL is already text-only. Both passing is the correct baseline — this task is a refactor, and these tests are the guard rail that proves it did not change behavior.

- [ ] **Step 3: Replace the inlined SQL**

In `DashboardController::index`, the `search` closure currently ends with a
driver-branching `if/elseif/else` block that adds a raw `EXISTS` for tag text.
Replace the whole block with the scope, and delete the now-unused `$driver`
variable if nothing else in the method uses it (check with
`grep -n '\$driver' app/Http/Controllers/DashboardController.php`):

```php
            ->when($search !== '', fn (Builder $query) => $query->where(function (Builder $query) use ($search) {
                $pattern = "%{$search}%";

                $query
                    ->whereLike('name', $pattern)
                    ->orWhereLike('slug', $pattern)
                    ->orWhereLike('description', $pattern)
                    ->orWhere(fn (Builder $inner) => $inner->whereTagLike($pattern));
            }))
```

`orWhereTagLike` does not exist — Laravel does not auto-generate `or` variants of
local scopes, so the scope is nested inside `orWhere(fn ...)`.

- [ ] **Step 4: Run the tests to verify they still pass**

Run: `php artisan test tests/Feature/DashboardTagSearchTest.php`
Expected: PASS, both tests — same behavior, less code.

- [ ] **Step 5: Commit**

```bash
git add app/Http/Controllers/DashboardController.php tests/Feature/DashboardTagSearchTest.php
git commit -m "refactor(dashboard): search tag text through the whereTagLike scope"
```

---

### Task 6: Stop truncating tuples in PHP call sites

**Files:**
- Modify: `app/Support/Apps/AppContext.php:205-207`
- Modify: `app/Http/Controllers/AppBuildPageController.php:82-88`
- Modify: `app/Support/Audit/AuditEntryPresenter.php:470-485`
- Test: `tests/Feature/Tags/TagSuggestionShapeTest.php` (create)

**Interfaces:**
- Consumes: the compacted shape (Task 2).
- Produces: suggestion lists that preserve index 2. No signature changes.

- [ ] **Step 1: Write the failing test**

Create `tests/Feature/Tags/TagSuggestionShapeTest.php`:

```php
<?php

namespace Tests\Feature\Tags;

use App\Models\AppIdentifier;
use App\Models\BuildRecord;
use App\Models\MobileApp;
use App\Models\Team;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class TagSuggestionShapeTest extends TestCase
{
    use RefreshDatabase;

    public function test_app_context_suggestions_keep_the_animation(): void
    {
        $user = User::factory()->create();
        $team = Team::factory()->create(['owner_id' => $user->id]);
        $team->users()->attach($user, ['role' => 'owner']);
        $user->update(['current_team_id' => $team->id]);

        $app = MobileApp::factory()->create(['team_id' => $team->id]);
        $app->syncTags([['flagship', '#abcdef', 'tada']])->save();

        $this->actingAs($user)
            ->get("/accounts/{$team->slug}/apps/{$app->slug}")
            ->assertInertia(fn (Assert $page) => $page
                ->where('tagSuggestions.0.0', 'flagship')
                ->where('tagSuggestions.0.2', 'tada'));
    }

    public function test_build_page_filter_tags_keep_the_animation(): void
    {
        $user = User::factory()->create();
        $team = Team::factory()->create(['owner_id' => $user->id]);
        $team->users()->attach($user, ['role' => 'owner']);
        $user->update(['current_team_id' => $team->id]);

        $app = MobileApp::factory()->create(['team_id' => $team->id]);
        $identifier = AppIdentifier::factory()->create(['mobile_app_id' => $app->id]);
        $build = BuildRecord::factory()->create([
            'team_id' => $team->id,
            'mobile_app_id' => $app->id,
            'app_identifier_id' => $identifier->id,
        ]);
        $build->syncTags([['nightly', null, 'pulse']])->save();

        $this->actingAs($user)
            ->get(route('accounts.apps.builds.index', [$team, $app]))
            ->assertInertia(fn (Assert $page) => $page
                ->where('filterTags.0.0', 'nightly')
                ->where('filterTags.0.2', 'pulse'));
    }
}
```

The exact prop name for the suggestion list in `AppContext` must be confirmed
first: `grep -n "Suggestions\|suggestions" app/Support/Apps/AppContext.php`. Use
whatever key it actually publishes instead of `tagSuggestions`.

- [ ] **Step 2: Run the test to verify it fails**

Run: `php artisan test tests/Feature/Tags/TagSuggestionShapeTest.php`
Expected: FAIL — index 2 is missing, because both call sites rebuild tuples as exactly two elements.

- [ ] **Step 3: Fix `AppContext`**

At `app/Support/Apps/AppContext.php:205-207`, the pipeline currently reads:

```php
            ->filter(fn ($tag) => filled($tag[0] ?? null))
            ->map(fn ($tag) => [$tag[0], $tag[1] ?? null])
            ->unique(fn ($tag) => mb_strtolower($tag[0]))
```

Drop the truncating `map` — `TagRules::normalize()` has already produced the
canonical shape on read, so re-shaping only loses data:

```php
            ->filter(fn ($tag) => filled(is_array($tag) ? ($tag[0] ?? null) : $tag))
            ->map(fn ($tag) => is_array($tag) ? $tag : [$tag])
            ->unique(fn ($tag) => mb_strtolower($tag[0]))
```

The `is_array` guard keeps the legacy-plain-string tolerance that the original
`filter` did not have but the build page's version did.

- [ ] **Step 4: Fix `AppBuildPageController`**

At `app/Http/Controllers/AppBuildPageController.php:82-88`, change the `map` the same way:

```php
                ->filter(fn ($tag) => filled(is_array($tag) ? ($tag[0] ?? null) : $tag))
                ->map(fn ($tag) => is_array($tag) ? $tag : [$tag])
                ->unique(fn ($tag) => mb_strtolower($tag[0]))
                ->sortBy(fn ($tag) => mb_strtolower($tag[0]))
                ->values(),
```

- [ ] **Step 5: Surface animation in the audit diff**

`app/Support/Audit/AuditEntryPresenter.php` around lines 470-485 builds a label
and a color per tag and is already null-safe. Add the animation alongside, so an
animation change shows up as a change rather than silently:

```php
            $label = is_string($tag[0] ?? null) ? trim($tag[0]) : '';
            ...
            $rawColor = $tag[1] ?? null;
            ...
            $animation = is_string($tag[2] ?? null) ? $tag[2] : null;
```

and include `'animation' => $animation` in the array this method returns for each
tag. Read the surrounding method first — the exact key names must match what
`resources/js/Components/AuditTagChange.svelte` consumes; that component takes the
value through unchanged, so adding a key is safe.

- [ ] **Step 6: Run the tests to verify they pass**

Run: `php artisan test tests/Feature/Tags/ tests/Feature/Audit/`
Expected: PASS.

- [ ] **Step 7: Commit**

```bash
git add app/Support/Apps/AppContext.php app/Http/Controllers/AppBuildPageController.php app/Support/Audit/AuditEntryPresenter.php tests/Feature/Tags/TagSuggestionShapeTest.php
git commit -m "fix(tags): stop truncating tuples in suggestion and audit payloads"
```

---

### Task 7: CSS keyframes and the JS token map

**Files:**
- Modify: `resources/css/app.css`
- Create: `resources/js/lib/tagAnimations.js`

**Interfaces:**
- Consumes: the token list from Task 1 (mirrored, not imported — this is JS).
- Produces: `TAG_ANIMATIONS` (an object keyed by token, each `{ label, class }`) and `animationClass(token)` returning `''` for `null`/unknown. Consumed by Tasks 8 and 9.

- [ ] **Step 1: Add the keyframes and utility classes**

Append to `resources/css/app.css`. The keyframes are copied from animate.css
(MIT) rather than adding the ~70KB dependency for three animations:

```css
/* Tag animations. flash/tada/heartBeat adapted from animate.css (MIT).
   Neutralized for reduced-motion users by the prefers-reduced-motion block above. */
@keyframes tag-flash {
    0%, 50%, 100% { opacity: 1; }
    25%, 75% { opacity: 0; }
}

@keyframes tag-tada {
    0% { transform: scale3d(1, 1, 1); }
    10%, 20% { transform: scale3d(0.9, 0.9, 0.9) rotate3d(0, 0, 1, -3deg); }
    30%, 50%, 70%, 90% { transform: scale3d(1.1, 1.1, 1.1) rotate3d(0, 0, 1, 3deg); }
    40%, 60%, 80% { transform: scale3d(1.1, 1.1, 1.1) rotate3d(0, 0, 1, -3deg); }
    100% { transform: scale3d(1, 1, 1); }
}

@keyframes tag-heartbeat {
    0% { transform: scale(1); }
    14% { transform: scale(1.3); }
    28% { transform: scale(1); }
    42% { transform: scale(1.3); }
    70% { transform: scale(1); }
}

.tag-anim-flash { animation: tag-flash 2s infinite; }
.tag-anim-tada { animation: tag-tada 1s infinite; }
.tag-anim-heartbeat { animation: tag-heartbeat 1.3s infinite; }
```

Verify the `prefers-reduced-motion` block (currently around lines 84-90) sits in a
plain `@media` rule that applies to `*`, so these classes are covered without
being named. If it is scoped to specific selectors, add the three classes to it.

- [ ] **Step 2: Create the token map**

Create `resources/js/lib/tagAnimations.js`:

```js
// Mirrors App\Enums\TagAnimation. Tokens are the canonical lowercase values
// stored at index 2 of a tag tuple; classes are what actually animates.
export const TAG_ANIMATIONS = {
    pulse: { label: 'Pulse', class: 'animate-pulse' },
    ping: { label: 'Ping', class: 'animate-ping' },
    flash: { label: 'Flash', class: 'tag-anim-flash' },
    tada: { label: 'Tada', class: 'tag-anim-tada' },
    heartbeat: { label: 'Heartbeat', class: 'tag-anim-heartbeat' },
};

export const TAG_ANIMATION_TOKENS = Object.keys(TAG_ANIMATIONS);

export function animationClass(token) {
    return TAG_ANIMATIONS[token]?.class ?? '';
}
```

- [ ] **Step 3: Verify the build**

Run: `npm run build`
Expected: succeeds. Tailwind 4 must not tree-shake the three `tag-anim-*` classes — they are authored in the CSS file itself, not generated utilities, so they survive; confirm by grepping the built CSS:

```bash
grep -c "tag-anim-heartbeat" public/build/assets/*.css
```

Expected: at least 1.

- [ ] **Step 4: Commit**

```bash
git add resources/css/app.css resources/js/lib/tagAnimations.js
git commit -m "feat(tags): add tag animation keyframes and the token map"
```

---

### Task 8: `TagEditor` animation picker

**Files:**
- Modify: `resources/js/Components/TagEditor.svelte`

**Interfaces:**
- Consumes: `TAG_ANIMATIONS`, `TAG_ANIMATION_TOKENS`, `animationClass` (Task 7).
- Produces: submits compacted tuples matching what the server stores.

The component stays in legacy Svelte mode (`export let` / `$:`). It persists through `router.patch` with local `saving` state rather than `useForm`, so the known legacy-mode problem with Inertia form re-rendering does not apply, and staying legacy keeps the diff to the feature itself.

- [ ] **Step 1: Import the map and add animation state**

```js
    import { TAG_ANIMATIONS, TAG_ANIMATION_TOKENS, animationClass } from '../lib/tagAnimations.js';
```

Add alongside the existing `let color = null;`:

```js
    let animation = null;
```

- [ ] **Step 2: Make `working` and `available` 3-aware**

Replace lines 34 and 37-41:

```js
    $: working = (tags || []).map((t) => (Array.isArray(t) ? [t[0], t[1] ?? null, t[2] ?? null] : [t, null, null]));
    $: canEdit = !disabled && !!url;
    $: toolClass = quiet && working.length ? 'row-tools' : '';
    $: available = (suggestions || [])
        .map((t) => (Array.isArray(t) ? [t[0], t[1] ?? null, t[2] ?? null] : [t, null, null]))
        .filter((t) => t[0] && !working.some((w) => w[0].toLowerCase() === t[0].toLowerCase()))
        .filter((t, i, arr) => arr.findIndex((x) => x[0].toLowerCase() === t[0].toLowerCase()) === i)
        .slice(0, 6);
```

- [ ] **Step 3: Compact on submit**

Add a helper next to `tint`, and use it in `submit`:

```js
    const compact = (t) => (t[2] ? [t[0], t[1] ?? null, t[2]] : t[1] ? [t[0], t[1]] : [t[0]]);

    function submit(next) {
        saving = true;
        router.patch(url, { tags: next.map(compact) }, { preserveScroll: true, onFinish: () => (saving = false) });
    }
```

- [ ] **Step 4: Thread animation through add/remove**

```js
    function addValue(value, c, a) {
        const v = (value || '').trim();
        if (!v) return;
        if (working.some((t) => t[0].toLowerCase() === v.toLowerCase())) return;
        const next = [...working, [v, c ?? null, a ?? null]];
        working = next;
        submit(next);
    }
    function addFromInput() {
        addValue(text, color, animation);
        closePop();
    }
    function addSuggestion(tag) {
        addValue(tag[0], tag[1], tag[2]);
        closePop();
    }
```

Reset it in `openPop()` beside `color = null;`:

```js
        animation = null;
```

- [ ] **Step 5: Animate the rendered badges**

On the badge `<span>` in the `{#each working ...}` block (line ~144), add the
animation class to the existing class list:

```svelte
            class="group/tag inline-flex items-center gap-1 rounded-md px-1.5 py-0.5 text-xs font-medium ring-1 ring-inset {animationClass(tag[2])}"
```

Do the same for the "Reuse" suggestion buttons in the popover (line ~219) so a
suggestion previews its animation.

- [ ] **Step 6: Add the picker row**

Insert after the color palette block (after the `</div>` that closes the
`mt-2.5 flex items-center gap-1.5` swatch row):

```svelte
        <div class="mt-2.5 flex flex-wrap items-center gap-1.5">
            <button
                type="button"
                class="rounded-sm px-2 py-1 text-xs font-medium shadow-none ring-1 ring-inset {animation === null ? 'ring-text' : 'ring-border'}"
                style="background-color:var(--surface-sunken);color:var(--text-muted)"
                aria-pressed={animation === null}
                on:click={() => (animation = null)}
            >
                No motion
            </button>
            {#each TAG_ANIMATION_TOKENS as token}
                <button
                    type="button"
                    class="rounded-sm px-2 py-1 text-xs font-medium shadow-none ring-1 ring-inset {animation === token ? 'ring-text' : 'ring-border'} {animation === token ? animationClass(token) : ''}"
                    style="background-color:var(--surface-sunken);color:var(--text-muted)"
                    aria-pressed={animation === token}
                    on:click={() => (animation = token)}
                >
                    {TAG_ANIMATIONS[token].label}
                </button>
            {/each}
        </div>
```

The chip animates only while selected, so the row is not five things moving at once.

- [ ] **Step 7: Verify the build and the design lint**

Run: `npm run verify`
Expected: `test:design`, `design:lint` and `vite build` all pass. `design:lint` covers `resources/js/Components/ui`, `resources/js/design`, `AppShell.svelte` and `WorkspacePage.svelte` — not `TagEditor.svelte` — but run the whole script anyway to catch a build break.

- [ ] **Step 8: Manual check**

Run: `composer dev`, open a team dashboard, add a tag with the Tada animation, confirm the badge animates and the value survives a page reload. Then check the same badge in the app overview and builds list, which render through different call sites.

- [ ] **Step 9: Commit**

```bash
git add resources/js/Components/TagEditor.svelte
git commit -m "feat(tags): pick a tag animation in the tag editor"
```

---

### Task 9: Stop truncating tuples in the Svelte call sites

**Files:**
- Modify: `resources/js/Pages/Apps/Builds/Index.svelte:41,112`
- Modify: `resources/js/Pages/Apps/Builds/Show.svelte:94`
- Modify: `resources/js/Pages/Apps/Identifiers/Show.svelte:175`

**Interfaces:**
- Consumes: nothing new.
- Produces: suggestion arrays that keep index 2, so `TagEditor` can preview and reuse an animation.

- [ ] **Step 1: Fix the three suggestion builders**

Each of the three files has the same line, pushing tuples into an accumulator:

```js
        const push = (list) => (list || []).forEach((t) => acc.push(Array.isArray(t) ? [t[0], t[1] ?? null] : [t, null]));
```

Replace each with:

```js
        const push = (list) => (list || []).forEach((t) => acc.push(Array.isArray(t) ? [t[0], t[1] ?? null, t[2] ?? null] : [t, null, null]));
```

- [ ] **Step 2: Fix the builds filter options**

`resources/js/Pages/Apps/Builds/Index.svelte:41` builds the tag filter dropdown.
It uses only text and color, which is correct for a filter chip — filters match on
text, and an animated dropdown option would be noise. Leave the shape but confirm
it does not crash on a one-element tuple:

```js
    $: tagOptions = filterTags.map((tag) => ({ value: tag[0], label: tag[0], color: tag[1] ?? null }));
```

This is already safe — `tag[1] ?? null` on `['nightly']` yields `null`. No change; note it in the commit body so a reviewer does not think it was missed.

- [ ] **Step 3: Verify the build**

Run: `npm run build`
Expected: succeeds.

- [ ] **Step 4: Manual check**

With `composer dev` running, open a build's tag editor on a build page and confirm the "Reuse" row offers a previously-created animated tag with its animation intact.

- [ ] **Step 5: Commit**

```bash
git add resources/js/Pages/Apps/Builds/Index.svelte resources/js/Pages/Apps/Builds/Show.svelte resources/js/Pages/Apps/Identifiers/Show.svelte
git commit -m "fix(tags): keep the animation in Svelte suggestion lists

Builds/Index tagOptions is intentionally left at text+color: filters match
on text only, so an animated dropdown option would just be noise."
```

---

### Task 10: Full verification

**Files:** none.

- [ ] **Step 1: Run the PHP suite**

Run: `php artisan test`
Expected: PASS, no skipped tag tests.

- [ ] **Step 2: Run the frontend checks**

Run: `npm run verify`
Expected: PASS.

- [ ] **Step 3: Confirm no migration was added**

Run: `git diff --stat main...HEAD -- database/migrations`
Expected: empty. A migration here contradicts the design.

- [ ] **Step 4: Confirm the no-backfill claim holds against a real old row**

This is already asserted by `HasTagsTraitTest::test_legacy_string_tags_can_be_read_removed_and_persisted` and the two-element rows written by other tests. Additionally confirm by hand:

```bash
php artisan tinker --execute="\
\$a = App\Models\MobileApp::first();
DB::table('mobile_apps')->where('id', \$a->id)->update(['tags' => json_encode([['legacy', null]])]);
dump(\$a->fresh()->tags);"
```

Expected output: `[['legacy']]`.

## Self-Review Notes

Spec coverage check against `2026-08-03-tags-flexible-shape-design.md`:

- Storage format and compaction → Task 2
- No data migration → Task 2 (cast unchanged), verified in Task 10 steps 3-4
- `TagAnimation` enum with `tryFromLoose` → Task 1
- No `cssClass()` on the enum → Task 1 (absent by design), mapping in Task 7
- `resources/js/lib/tagAnimations.js` → Task 7
- CSS keyframes, animate.css not added → Task 7
- `TagRules::rules()` third field via closure → Task 2
- `HasTags::addTag` animation, `whereAllTags`, `whereTagLike` → Task 4
- `DashboardController` rewritten onto the scope → Task 5
- Cast docblock → Task 4 step 4
- `TagEditor` picker → Task 8
- PHP call sites (AppContext, AppBuildPageController, AuditEntryPresenter) → Task 6
- Svelte call sites → Task 9
- Every test listed in the spec's Testing section → Tasks 1, 2, 4, 5, 6

Deviation from the spec worth flagging to the reviewer: the spec's `SearchFilter`
sketch and the `whereTagLike` section imply an `orWhereTagLike`. Laravel does not
auto-generate `or` variants of local scopes, so both this plan (Task 5) and the
filters plan nest the scope in `orWhere(fn ...)` instead. Same result, and the
filters plan repeats the note.

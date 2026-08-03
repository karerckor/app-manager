# Query Filters and API Tag Filtering Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Introduce a reusable query-filter layer and use it to give the four taggable v1 API index endpoints tag filtering and text search.

**Architecture:** A filter owns its request keys — both their validation rules and their effect on the query. `FilterSet` merges rules and applies members in order; the `AppliesFilters` controller trait wires validation to application in one call. Two concrete filters ship: `TagFilter` (exact tag-text matching, `any`/`all`) and `SearchFilter` (partial matching over declared columns plus tag text). Controllers declare which filters an endpoint accepts and nothing more.

**Tech Stack:** Laravel 12 (PHP 8.3+), Sanctum team API keys, PHPUnit with `Tests\TestCase` + `RefreshDatabase`.

**Spec:** `docs/superpowers/specs/2026-08-03-query-filters-api-design.md`

**Prerequisite:** `docs/superpowers/plans/2026-08-03-tags-flexible-shape.md` must be complete. This plan calls `whereAllTags()` and `whereTagLike()`, which that plan adds.

## Global Constraints

- Run tests from `apps/platform`. Full suite: `php artisan test`. Single file: `php artisan test tests/Unit/Filters/TagFilterTest.php`.
- Every filter parameter is optional. Absent parameters must reproduce today's behavior exactly — this is not a breaking change and the existing API suite must stay green.
- Tag matching is on tag **text** only, never color or animation.
- `tags_mode` accepts exactly `any` and `all`; absent means `any`.
- Parameter limits, exact values: `tags` max 50 entries, each entry max `TagRules::MAX_TEXT_LENGTH` (50) chars; `search` max 100 chars.
- Validation failures surface as 422 through `$request->validate()`, matching the rest of the API.
- Filters compose onto an already tenant-scoped builder. Never touch the existing tenant guards (`assertApp`, `assertBuildBelongs`, the `$team->credentials()` relation root).
- `orWhereTagLike` does not exist — Laravel does not auto-generate `or` variants of local scopes. Nest the scope: `$query->orWhere(fn ($inner) => $inner->whereTagLike($pattern))`.
- Route paths, confirmed against `routes/api.php`:
  - `GET /api/v1/{team:slug}/apps` — ability `apps:read`
  - `GET /api/v1/{team:slug}/apps/{app:slug}/builds` — ability `builds:read`
  - `GET /api/v1/{team:slug}/apps/{app:slug}/credentials` — ability `credentials:read`
  - `GET /api/v1/{team:slug}/apps/{app:slug}/builds/{build}/artifacts` — ability `artifacts:read`

## File Structure

**Create:**
- `app/Support/Filters/Filter.php` — the contract: rules + apply.
- `app/Support/Filters/FilterSet.php` — merges rules, applies members in order.
- `app/Support/Filters/TagFilter.php` — owns `tags` and `tags_mode`.
- `app/Support/Filters/SearchFilter.php` — owns `search`.
- `app/Http/Concerns/AppliesFilters.php` — controller entry point, beside `ResolvesPageSize`.
- `tests/Support/InteractsWithApiV1.php` — shared API test helpers, beside the existing `tests/Support/UsesEncryptionKeys.php`.
- `tests/Unit/Filters/FilterSetTest.php`, `tests/Unit/Filters/TagFilterTest.php`, `tests/Unit/Filters/SearchFilterTest.php`
- `tests/Feature/Api/V1/IndexFilteringTest.php` — one feature file covering all four endpoints.

**Modify:**
- `app/Http/Controllers/Api/V1/MobileAppController.php` — `index()`
- `app/Http/Controllers/Api/V1/BuildController.php` — `index()`
- `app/Http/Controllers/Api/V1/CredentialController.php` — `index()`
- `app/Http/Controllers/Api/V1/ArtifactController.php` — `index()`

---

### Task 1: `Filter` contract and `FilterSet`

**Files:**
- Create: `app/Support/Filters/Filter.php`, `app/Support/Filters/FilterSet.php`
- Test: `tests/Unit/Filters/FilterSetTest.php`

**Interfaces:**
- Consumes: nothing.
- Produces:
  - `interface Filter { public function rules(): array; public function apply(Builder $query, array $data): void; }`
  - `final class FilterSet { public function __construct(array $filters); public function rules(): array; public function apply(Builder $query, array $data): Builder; }`

  Consumed by every later task.

`apply()` receives the whole validated payload, not just its own slice, because a filter may own several keys (`TagFilter` owns two) and one array keeps the signature stable.

- [ ] **Step 1: Write the failing test**

Create `tests/Unit/Filters/FilterSetTest.php`:

```php
<?php

namespace Tests\Unit\Filters;

use App\Models\MobileApp;
use App\Support\Filters\Filter;
use App\Support\Filters\FilterSet;
use ArrayObject;
use Illuminate\Database\Eloquent\Builder;
use Tests\TestCase;

class FilterSetTest extends TestCase
{
    public function test_rules_merges_every_member(): void
    {
        $set = new FilterSet([
            $this->filter(['a' => ['nullable']], 'a'),
            $this->filter(['b' => ['required']], 'b'),
        ]);

        $this->assertSame(['a' => ['nullable'], 'b' => ['required']], $set->rules());
    }

    public function test_rules_of_an_empty_set_is_an_empty_array(): void
    {
        $this->assertSame([], (new FilterSet([]))->rules());
    }

    public function test_apply_runs_members_in_declaration_order_and_returns_the_builder(): void
    {
        $calls = new ArrayObject();
        $query = MobileApp::query();

        $set = new FilterSet([
            $this->filter([], 'first', $calls),
            $this->filter([], 'second', $calls),
        ]);

        $this->assertSame($query, $set->apply($query, []));
        $this->assertSame(['first', 'second'], $calls->getArrayCopy());
    }

    /**
     * A stub filter that records that it ran. The recorder is an ArrayObject
     * because a promoted constructor property cannot be declared by reference.
     *
     * @param  array<string, array<int, mixed>>  $rules
     */
    private function filter(array $rules, string $name, ?ArrayObject $calls = null): Filter
    {
        return new class($rules, $name, $calls ?? new ArrayObject()) implements Filter
        {
            public function __construct(
                private readonly array $rules,
                private readonly string $name,
                private readonly ArrayObject $calls,
            ) {}

            public function rules(): array
            {
                return $this->rules;
            }

            public function apply(Builder $query, array $data): void
            {
                $this->calls[] = $this->name;
            }
        };
    }
}
```

- [ ] **Step 2: Run the test to verify it fails**

Run: `php artisan test tests/Unit/Filters/FilterSetTest.php`
Expected: FAIL — `Interface "App\Support\Filters\Filter" not found`.

- [ ] **Step 3: Write the implementation**

Create `app/Support/Filters/Filter.php`:

```php
<?php

namespace App\Support\Filters;

use Illuminate\Database\Eloquent\Builder;

interface Filter
{
    /**
     * Validation rules for the request keys this filter owns.
     *
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array;

    /**
     * Apply this filter to the query. A filter with no applicable input must
     * return without touching the query — deciding "am I active?" belongs here,
     * not in the caller.
     *
     * @param  array<string, mixed>  $data  Validated request data.
     */
    public function apply(Builder $query, array $data): void;
}
```

Create `app/Support/Filters/FilterSet.php`:

```php
<?php

namespace App\Support\Filters;

use Illuminate\Database\Eloquent\Builder;

final class FilterSet
{
    /**
     * @param  list<Filter>  $filters
     */
    public function __construct(private readonly array $filters) {}

    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        if ($this->filters === []) {
            return [];
        }

        return array_merge(...array_map(fn (Filter $filter) => $filter->rules(), $this->filters));
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function apply(Builder $query, array $data): Builder
    {
        foreach ($this->filters as $filter) {
            $filter->apply($query, $data);
        }

        return $query;
    }
}
```

- [ ] **Step 4: Run the test to verify it passes**

Run: `php artisan test tests/Unit/Filters/FilterSetTest.php`
Expected: PASS, 3 tests.

- [ ] **Step 5: Commit**

```bash
git add app/Support/Filters/Filter.php app/Support/Filters/FilterSet.php tests/Unit/Filters/FilterSetTest.php
git commit -m "feat(filters): add the Filter contract and FilterSet"
```

---

### Task 2: `TagFilter`

**Files:**
- Create: `app/Support/Filters/TagFilter.php`
- Test: `tests/Unit/Filters/TagFilterTest.php`

**Interfaces:**
- Consumes: `Filter` (Task 1); `whereAnyTag()` and `whereAllTags()` from `HasTags`.
- Produces: `new TagFilter()` — no constructor arguments. Owns request keys `tags` and `tags_mode`. Public constants `TagFilter::MODE_ANY = 'any'` and `TagFilter::MODE_ALL = 'all'`.

- [ ] **Step 1: Write the failing test**

Create `tests/Unit/Filters/TagFilterTest.php`:

```php
<?php

namespace Tests\Unit\Filters;

use App\Models\MobileApp;
use App\Models\Team;
use App\Models\User;
use App\Support\Filters\TagFilter;
use App\Support\Tags\TagRules;
use Illuminate\Foundation\Testing\RefreshDatabase;
use LogicException;
use Tests\TestCase;

class TagFilterTest extends TestCase
{
    use RefreshDatabase;

    public function test_rules_declare_both_owned_keys(): void
    {
        $rules = (new TagFilter())->rules();

        $this->assertArrayHasKey('tags', $rules);
        $this->assertArrayHasKey('tags.*', $rules);
        $this->assertArrayHasKey('tags_mode', $rules);
        $this->assertContains('max:50', $rules['tags']);
        $this->assertContains('max:'.TagRules::MAX_TEXT_LENGTH, $rules['tags.*']);
        $this->assertContains('in:any,all', $rules['tags_mode']);
    }

    public function test_any_mode_is_the_default(): void
    {
        [$both, $one, $neither] = $this->apps();

        $query = MobileApp::query();
        (new TagFilter())->apply($query, ['tags' => ['beta', 'qa']]);
        $ids = $query->pluck('id');

        $this->assertTrue($ids->contains($both->id));
        $this->assertTrue($ids->contains($one->id));
        $this->assertFalse($ids->contains($neither->id));
    }

    public function test_all_mode_requires_every_tag(): void
    {
        [$both, $one] = $this->apps();

        $query = MobileApp::query();
        (new TagFilter())->apply($query, ['tags' => ['beta', 'qa'], 'tags_mode' => 'all']);
        $ids = $query->pluck('id');

        $this->assertTrue($ids->contains($both->id));
        $this->assertFalse($ids->contains($one->id));
    }

    public function test_blank_and_absent_input_is_a_no_op(): void
    {
        [, , $neither] = $this->apps();

        foreach ([[], ['tags' => []], ['tags' => ['   ', '']]] as $data) {
            $query = MobileApp::query();
            (new TagFilter())->apply($query, $data);

            $this->assertTrue(
                $query->pluck('id')->contains($neither->id),
                'An inactive tag filter must not narrow the query.',
            );
        }
    }

    public function test_it_throws_when_the_model_has_no_tags(): void
    {
        $this->expectException(LogicException::class);

        (new TagFilter())->apply(User::query(), ['tags' => ['beta']]);
    }

    /** @return array{MobileApp, MobileApp, MobileApp} */
    private function apps(): array
    {
        $team = Team::factory()->create();

        $both = MobileApp::factory()->create(['team_id' => $team->id]);
        $both->syncTags([['beta', null], ['qa', null]])->save();

        $one = MobileApp::factory()->create(['team_id' => $team->id]);
        $one->syncTags([['beta', null]])->save();

        $neither = MobileApp::factory()->create(['team_id' => $team->id]);

        return [$both, $one, $neither];
    }
}
```

`test_it_throws_when_the_model_has_no_tags` uses `User::query()` because `User`
is a model that does not use `HasTags`. Confirm that is still true before relying
on it: `grep -n "HasTags" app/Models/User.php` must find nothing.

- [ ] **Step 2: Run the test to verify it fails**

Run: `php artisan test tests/Unit/Filters/TagFilterTest.php`
Expected: FAIL — `Class "App\Support\Filters\TagFilter" not found`.

- [ ] **Step 3: Write the implementation**

Create `app/Support/Filters/TagFilter.php`:

```php
<?php

namespace App\Support\Filters;

use App\Models\Concerns\HasTags;
use App\Support\Tags\TagRules;
use Illuminate\Database\Eloquent\Builder;
use LogicException;

class TagFilter implements Filter
{
    public const MODE_ANY = 'any';

    public const MODE_ALL = 'all';

    public function rules(): array
    {
        return [
            'tags' => ['nullable', 'array', 'max:50'],
            'tags.*' => ['string', 'max:'.TagRules::MAX_TEXT_LENGTH],
            'tags_mode' => ['nullable', 'string', 'in:'.self::MODE_ANY.','.self::MODE_ALL],
        ];
    }

    public function apply(Builder $query, array $data): void
    {
        $texts = array_values(array_filter(
            array_map(fn ($text) => trim((string) $text), $data['tags'] ?? []),
            fn (string $text) => $text !== '',
        ));

        if ($texts === []) {
            return;
        }

        $model = $query->getModel();

        if (! in_array(HasTags::class, class_uses_recursive($model), true)) {
            throw new LogicException(sprintf(
                '%s cannot filter %s: the model does not use %s.',
                static::class,
                $model::class,
                HasTags::class,
            ));
        }

        ($data['tags_mode'] ?? self::MODE_ANY) === self::MODE_ALL
            ? $query->whereAllTags($texts)
            : $query->whereAnyTag($texts);
    }
}
```

- [ ] **Step 4: Run the test to verify it passes**

Run: `php artisan test tests/Unit/Filters/TagFilterTest.php`
Expected: PASS, 5 tests.

- [ ] **Step 5: Commit**

```bash
git add app/Support/Filters/TagFilter.php tests/Unit/Filters/TagFilterTest.php
git commit -m "feat(filters): add TagFilter with any/all matching"
```

---

### Task 3: `SearchFilter`

**Files:**
- Create: `app/Support/Filters/SearchFilter.php`
- Test: `tests/Unit/Filters/SearchFilterTest.php`

**Interfaces:**
- Consumes: `Filter` (Task 1); `whereTagLike()` from `HasTags`.
- Produces: `new SearchFilter(array $columns, bool $includeTags = false)`. Owns the request key `search`.

- [ ] **Step 1: Write the failing test**

Create `tests/Unit/Filters/SearchFilterTest.php`:

```php
<?php

namespace Tests\Unit\Filters;

use App\Models\MobileApp;
use App\Models\Team;
use App\Support\Filters\SearchFilter;
use App\Support\Filters\TagFilter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SearchFilterTest extends TestCase
{
    use RefreshDatabase;

    public function test_rules_declare_the_search_key(): void
    {
        $rules = (new SearchFilter(['name']))->rules();

        $this->assertArrayHasKey('search', $rules);
        $this->assertContains('nullable', $rules['search']);
        $this->assertContains('max:100', $rules['search']);
    }

    public function test_it_matches_any_declared_column(): void
    {
        $team = Team::factory()->create();
        $byName = MobileApp::factory()->create(['team_id' => $team->id, 'name' => 'Onboarding Kit']);
        $bySlug = MobileApp::factory()->create(['team_id' => $team->id, 'name' => 'Other', 'slug' => 'onboard-me']);
        $miss = MobileApp::factory()->create(['team_id' => $team->id, 'name' => 'Zulu', 'slug' => 'zulu']);

        $query = MobileApp::query();
        (new SearchFilter(['name', 'slug']))->apply($query, ['search' => 'onboard']);
        $ids = $query->pluck('id');

        $this->assertTrue($ids->contains($byName->id));
        $this->assertTrue($ids->contains($bySlug->id));
        $this->assertFalse($ids->contains($miss->id));
    }

    public function test_it_matches_tag_text_when_tags_are_included(): void
    {
        $team = Team::factory()->create();
        $tagged = MobileApp::factory()->create(['team_id' => $team->id, 'name' => 'Zulu']);
        $tagged->syncTags([['Onboarding', '#10b981', 'pulse']])->save();

        $query = MobileApp::query();
        (new SearchFilter(['name'], includeTags: true))->apply($query, ['search' => 'onb']);

        $this->assertTrue($query->pluck('id')->contains($tagged->id));
    }

    public function test_it_never_matches_a_tag_color_or_animation(): void
    {
        $team = Team::factory()->create();
        $tagged = MobileApp::factory()->create(['team_id' => $team->id, 'name' => 'Zulu']);
        $tagged->syncTags([['beta', '#10b981', 'pulse']])->save();

        foreach (['10b981', 'pulse'] as $needle) {
            $query = MobileApp::query();
            (new SearchFilter(['name'], includeTags: true))->apply($query, ['search' => $needle]);

            $this->assertFalse(
                $query->pluck('id')->contains($tagged->id),
                "Search must not match the tag {$needle}.",
            );
        }
    }

    public function test_it_does_not_match_tag_text_when_tags_are_excluded(): void
    {
        $team = Team::factory()->create();
        $tagged = MobileApp::factory()->create(['team_id' => $team->id, 'name' => 'Zulu']);
        $tagged->syncTags([['Onboarding', null]])->save();

        $query = MobileApp::query();
        (new SearchFilter(['name']))->apply($query, ['search' => 'onb']);

        $this->assertFalse($query->pluck('id')->contains($tagged->id));
    }

    public function test_blank_and_absent_input_is_a_no_op(): void
    {
        $team = Team::factory()->create();
        $app = MobileApp::factory()->create(['team_id' => $team->id, 'name' => 'Zulu']);

        foreach ([[], ['search' => ''], ['search' => '   ']] as $data) {
            $query = MobileApp::query();
            (new SearchFilter(['name']))->apply($query, $data);

            $this->assertTrue($query->pluck('id')->contains($app->id));
        }
    }

    public function test_its_or_group_does_not_leak_past_another_filter(): void
    {
        $team = Team::factory()->create();
        $match = MobileApp::factory()->create(['team_id' => $team->id, 'name' => 'Onboarding']);
        $match->syncTags([['beta', null]])->save();
        // Same name, no tag: must be excluded by the tag filter despite matching search.
        $nameOnly = MobileApp::factory()->create(['team_id' => $team->id, 'name' => 'Onboarding Two']);

        $query = MobileApp::query();
        (new SearchFilter(['name'], includeTags: true))->apply($query, ['search' => 'onboard']);
        (new TagFilter())->apply($query, ['tags' => ['beta']]);
        $ids = $query->pluck('id');

        $this->assertTrue($ids->contains($match->id));
        $this->assertFalse($ids->contains($nameOnly->id));
    }
}
```

- [ ] **Step 2: Run the test to verify it fails**

Run: `php artisan test tests/Unit/Filters/SearchFilterTest.php`
Expected: FAIL — `Class "App\Support\Filters\SearchFilter" not found`.

- [ ] **Step 3: Write the implementation**

Create `app/Support/Filters/SearchFilter.php`:

```php
<?php

namespace App\Support\Filters;

use Illuminate\Database\Eloquent\Builder;

class SearchFilter implements Filter
{
    public const MAX_LENGTH = 100;

    /**
     * @param  list<string>  $columns  Columns on the queried model.
     * @param  bool  $includeTags  Also match the text of the model's tags.
     */
    public function __construct(
        private readonly array $columns,
        private readonly bool $includeTags = false,
    ) {}

    public function rules(): array
    {
        return [
            'search' => ['nullable', 'string', 'max:'.self::MAX_LENGTH],
        ];
    }

    public function apply(Builder $query, array $data): void
    {
        $search = trim((string) ($data['search'] ?? ''));

        if ($search === '') {
            return;
        }

        $pattern = "%{$search}%";

        // The group is mandatory: an ungrouped OR would escape and defeat the
        // tenant scope and any other filter on the query.
        $query->where(function (Builder $group) use ($pattern) {
            foreach ($this->columns as $column) {
                $group->orWhereLike($group->qualifyColumn($column), $pattern);
            }

            if ($this->includeTags) {
                // Laravel has no orWhereTagLike — local scopes get no `or` variant.
                $group->orWhere(fn (Builder $inner) => $inner->whereTagLike($pattern));
            }
        });
    }
}
```

The leading `orWhereLike` inside the group is safe: the query grammar strips a
leading boolean when compiling a where group, so the first condition is emitted
without its `or`.

- [ ] **Step 4: Run the test to verify it passes**

Run: `php artisan test tests/Unit/Filters/SearchFilterTest.php`
Expected: PASS, 7 tests.

- [ ] **Step 5: Commit**

```bash
git add app/Support/Filters/SearchFilter.php tests/Unit/Filters/SearchFilterTest.php
git commit -m "feat(filters): add SearchFilter over columns and tag text"
```

---

### Task 4: `AppliesFilters` trait and the apps endpoint

**Files:**
- Create: `app/Http/Concerns/AppliesFilters.php`
- Create: `tests/Support/InteractsWithApiV1.php`
- Create: `tests/Feature/Api/V1/IndexFilteringTest.php`
- Modify: `app/Http/Controllers/Api/V1/MobileAppController.php` — `index()` at lines 28-33

**Interfaces:**
- Consumes: `FilterSet` (Task 1), `TagFilter` (Task 2), `SearchFilter` (Task 3).
- Produces:
  - `AppliesFilters::filter(Request $request, Builder $query, Filter ...$filters): Builder` — validates then applies.
  - `Tests\Support\InteractsWithApiV1` with `apiContext(): array{User, Team, MobileApp, AppIdentifier}`, `authenticateApi(User $user, Team $team, array $scopes): void`, `apiUrl(Team $team, string $path): string`.

This task carries the first endpoint because the trait is not meaningfully testable without one.

- [ ] **Step 1: Write the shared test helpers**

Create `tests/Support/InteractsWithApiV1.php`, lifting the private helpers that
`tests/Feature/Api/V1/BuildTagsApiTest.php` already defines so the four endpoint
tests do not each redeclare them:

```php
<?php

namespace Tests\Support;

use App\Enums\ApiKeyScope;
use App\Models\AppIdentifier;
use App\Models\MobileApp;
use App\Models\Team;
use App\Models\User;

trait InteractsWithApiV1
{
    /** @return array{User, Team, MobileApp, AppIdentifier} */
    protected function apiContext(): array
    {
        $user = User::factory()->create();
        $team = Team::factory()->create(['owner_id' => $user->id]);
        $team->users()->attach($user, ['role' => 'owner']);
        $user->update(['current_team_id' => $team->id]);
        $app = MobileApp::factory()->create(['team_id' => $team->id]);
        $identifier = AppIdentifier::factory()->create(['mobile_app_id' => $app->id]);

        return [$user, $team, $app, $identifier];
    }

    /** @param list<ApiKeyScope> $scopes */
    protected function authenticateApi(User $user, Team $team, array $scopes): void
    {
        $token = $user->createTeamApiKey(
            $team,
            'Runner',
            array_map(fn (ApiKeyScope $scope) => $scope->value, $scopes),
        )->plainTextToken;

        $this->app['auth']->guard('sanctum')->forgetUser();
        $this->withToken($token);
    }

    protected function apiUrl(Team $team, string $path): string
    {
        return "/api/v1/{$team->slug}{$path}";
    }
}
```

- [ ] **Step 2: Write the failing test**

Create `tests/Feature/Api/V1/IndexFilteringTest.php`:

```php
<?php

namespace Tests\Feature\Api\V1;

use App\Enums\ApiKeyScope;
use App\Models\MobileApp;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\InteractsWithApiV1;
use Tests\TestCase;

class IndexFilteringTest extends TestCase
{
    use InteractsWithApiV1, RefreshDatabase;

    public function test_apps_can_be_filtered_by_a_single_tag(): void
    {
        [$user, $team, $tagged] = $this->apiContext();
        $tagged->syncTags([['beta', null]])->save();
        $untagged = MobileApp::factory()->create(['team_id' => $team->id]);
        $this->authenticateApi($user, $team, [ApiKeyScope::AppsRead]);

        $ids = collect($this->getJson($this->apiUrl($team, '/apps?tags[]=beta'))
            ->assertOk()
            ->json('data'))
            ->pluck('id');

        $this->assertTrue($ids->contains($tagged->id));
        $this->assertFalse($ids->contains($untagged->id));
    }

    public function test_apps_tag_filter_defaults_to_any(): void
    {
        [$user, $team, $beta] = $this->apiContext();
        $beta->syncTags([['beta', null]])->save();
        $qa = MobileApp::factory()->create(['team_id' => $team->id]);
        $qa->syncTags([['qa', null]])->save();
        $this->authenticateApi($user, $team, [ApiKeyScope::AppsRead]);

        $ids = collect($this->getJson($this->apiUrl($team, '/apps?tags[]=beta&tags[]=qa'))
            ->assertOk()
            ->json('data'))
            ->pluck('id');

        $this->assertTrue($ids->contains($beta->id));
        $this->assertTrue($ids->contains($qa->id));
    }

    public function test_apps_tags_mode_all_requires_every_tag(): void
    {
        [$user, $team, $both] = $this->apiContext();
        $both->syncTags([['beta', null], ['qa', null]])->save();
        $one = MobileApp::factory()->create(['team_id' => $team->id]);
        $one->syncTags([['beta', null]])->save();
        $this->authenticateApi($user, $team, [ApiKeyScope::AppsRead]);

        $ids = collect($this->getJson($this->apiUrl($team, '/apps?tags[]=beta&tags[]=qa&tags_mode=all'))
            ->assertOk()
            ->json('data'))
            ->pluck('id');

        $this->assertTrue($ids->contains($both->id));
        $this->assertFalse($ids->contains($one->id));
    }

    public function test_apps_search_matches_tag_text_alone(): void
    {
        [$user, $team, $tagged] = $this->apiContext();
        $tagged->update(['name' => 'Zulu']);
        $tagged->syncTags([['Onboarding', null]])->save();
        $this->authenticateApi($user, $team, [ApiKeyScope::AppsRead]);

        $ids = collect($this->getJson($this->apiUrl($team, '/apps?search=onb'))
            ->assertOk()
            ->json('data'))
            ->pluck('id');

        $this->assertTrue($ids->contains($tagged->id));
    }

    public function test_apps_filters_do_not_leak_across_teams(): void
    {
        [$user, $team] = $this->apiContext();
        [, $otherTeam, $foreignApp] = $this->apiContext();
        $foreignApp->syncTags([['beta', null]])->save();
        $this->authenticateApi($user, $team, [ApiKeyScope::AppsRead]);

        $ids = collect($this->getJson($this->apiUrl($team, '/apps?tags[]=beta'))
            ->assertOk()
            ->json('data'))
            ->pluck('id');

        $this->assertFalse($ids->contains($foreignApp->id));
    }

    public function test_apps_pagination_links_preserve_the_filters(): void
    {
        [$user, $team, $first] = $this->apiContext();
        $first->syncTags([['beta', null]])->save();

        foreach (range(1, 30) as $ignored) {
            MobileApp::factory()->create(['team_id' => $team->id])->syncTags([['beta', null]])->save();
        }

        $this->authenticateApi($user, $team, [ApiKeyScope::AppsRead]);

        $next = $this->getJson($this->apiUrl($team, '/apps?tags[]=beta&per_page=5'))
            ->assertOk()
            ->json('links.next');

        // Assert on the decoded link so the test does not depend on whether
        // Laravel emits tags[0]=beta or tags%5B0%5D=beta.
        $this->assertStringContainsString('tags', urldecode($next));
        $this->assertStringContainsString('beta', urldecode($next));
        $this->assertStringContainsString('per_page=5', $next);
    }

    public function test_apps_reject_an_unknown_tags_mode(): void
    {
        [$user, $team] = $this->apiContext();
        $this->authenticateApi($user, $team, [ApiKeyScope::AppsRead]);

        $this->getJson($this->apiUrl($team, '/apps?tags[]=beta&tags_mode=sometimes'))
            ->assertStatus(422)
            ->assertJsonValidationErrors('tags_mode');
    }

    public function test_apps_index_without_filters_returns_everything(): void
    {
        [$user, $team, $app] = $this->apiContext();
        $other = MobileApp::factory()->create(['team_id' => $team->id]);
        $this->authenticateApi($user, $team, [ApiKeyScope::AppsRead]);

        $ids = collect($this->getJson($this->apiUrl($team, '/apps'))->assertOk()->json('data'))->pluck('id');

        $this->assertTrue($ids->contains($app->id));
        $this->assertTrue($ids->contains($other->id));
    }
}
```

`test_apps_pagination_links_preserve_the_filters` seeds 31 apps so that a
`per_page=5` request actually produces a `next` link.

- [ ] **Step 3: Run the test to verify it fails**

Run: `php artisan test tests/Feature/Api/V1/IndexFilteringTest.php`
Expected: FAIL — the tag tests return every app because `index()` ignores the parameters, and the 422 test returns 200.

- [ ] **Step 4: Write the trait**

Create `app/Http/Concerns/AppliesFilters.php`:

```php
<?php

namespace App\Http\Concerns;

use App\Support\Filters\Filter;
use App\Support\Filters\FilterSet;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;

trait AppliesFilters
{
    /**
     * Validate the filter parameters this endpoint accepts, then apply them.
     * Validation stays outside the filters so failures surface as the usual 422.
     */
    protected function filter(Request $request, Builder $query, Filter ...$filters): Builder
    {
        $set = new FilterSet($filters);

        return $set->apply($query, $request->validate($set->rules()));
    }
}
```

- [ ] **Step 5: Wire `MobileAppController::index`**

Add to the imports:

```php
use App\Http\Concerns\AppliesFilters;
use App\Support\Filters\SearchFilter;
use App\Support\Filters\TagFilter;
```

Add the trait to the `use` line inside the class (it currently reads
`use ResolvesPageSize;`):

```php
    use AppliesFilters, ResolvesPageSize;
```

Replace `index()`:

```php
    public function index(Request $request, Team $team): AnonymousResourceCollection
    {
        $apps = $this->filter(
            $request,
            $team->mobileApps(),
            new SearchFilter(['name', 'slug', 'description'], includeTags: true),
            new TagFilter(),
        );

        return MobileAppResource::collection(
            $apps->latest()->paginate($this->perPage($request))->withQueryString(),
        );
    }
```

`$team->mobileApps()` is a relation, not a `Builder`. Relations proxy `Builder`
methods, but the `Filter::apply()` signature is typed `Builder`. Pass
`$team->mobileApps()->getQuery()` if PHP complains about the type; verify by
running the test rather than guessing.

- [ ] **Step 6: Run the test to verify it passes**

Run: `php artisan test tests/Feature/Api/V1/IndexFilteringTest.php`
Expected: PASS, 8 tests.

- [ ] **Step 7: Run the existing API suite for regressions**

Run: `php artisan test tests/Feature/Api/`
Expected: PASS. `withQueryString()` changes the shape of pagination links; if an existing test pinned an exact link string, it needs updating — that is a correct consequence, not a regression to revert.

- [ ] **Step 8: Commit**

```bash
git add app/Http/Concerns/AppliesFilters.php app/Http/Controllers/Api/V1/MobileAppController.php tests/Support/InteractsWithApiV1.php tests/Feature/Api/V1/IndexFilteringTest.php
git commit -m "feat(api): filter and search the apps index by tags"
```

---

### Task 5: Builds endpoint

**Files:**
- Modify: `app/Http/Controllers/Api/V1/BuildController.php` — `index()` at lines 31-38
- Test: `tests/Feature/Api/V1/IndexFilteringTest.php`

**Interfaces:**
- Consumes: `AppliesFilters` (Task 4), `TagFilter`, `SearchFilter`.
- Produces: nothing new.

`BuildRecord` has no `name` column. Per its `#[Fillable]` attribute the free-text identifiers are `request_uuid` and `expo_build_id`.

Note: this controller is already modified by unrelated in-flight work on the `feat/credentials-byov` branch. Read the current `index()` before editing.

- [ ] **Step 1: Write the failing test**

Add to `tests/Feature/Api/V1/IndexFilteringTest.php` (add `use App\Models\BuildRecord;`):

```php
    public function test_builds_can_be_filtered_by_tag_and_searched_by_expo_id(): void
    {
        [$user, $team, $app, $identifier] = $this->apiContext();

        $tagged = BuildRecord::factory()->create([
            'team_id' => $team->id,
            'mobile_app_id' => $app->id,
            'app_identifier_id' => $identifier->id,
            'expo_build_id' => 'expo-abc-123',
        ]);
        $tagged->syncTags([['nightly', null]])->save();

        $plain = BuildRecord::factory()->create([
            'team_id' => $team->id,
            'mobile_app_id' => $app->id,
            'app_identifier_id' => $identifier->id,
            'expo_build_id' => 'expo-zzz-999',
        ]);

        $this->authenticateApi($user, $team, [ApiKeyScope::BuildsRead]);
        $path = "/apps/{$app->slug}/builds";

        $byTag = collect($this->getJson($this->apiUrl($team, $path.'?tags[]=nightly'))
            ->assertOk()->json('data'))->pluck('id');
        $this->assertTrue($byTag->contains($tagged->id));
        $this->assertFalse($byTag->contains($plain->id));

        $bySearch = collect($this->getJson($this->apiUrl($team, $path.'?search=zzz'))
            ->assertOk()->json('data'))->pluck('id');
        $this->assertTrue($bySearch->contains($plain->id));
        $this->assertFalse($bySearch->contains($tagged->id));
    }
```

- [ ] **Step 2: Run the test to verify it fails**

Run: `php artisan test --filter test_builds_can_be_filtered_by_tag_and_searched_by_expo_id`
Expected: FAIL — both builds come back from both requests.

- [ ] **Step 3: Wire the controller**

Add the imports and the trait as in Task 4 step 5, then replace `index()`:

```php
    public function index(Request $request, Team $team, MobileApp $mobileApp): AnonymousResourceCollection
    {
        $this->assertApp($team, $mobileApp);

        $builds = $this->filter(
            $request,
            $mobileApp->buildRecords()->with('ciCdRun'),
            new SearchFilter(['request_uuid', 'expo_build_id'], includeTags: true),
            new TagFilter(),
        );

        return BuildRecordResource::collection(
            $builds->latest()->paginate($this->perPage($request, default: 50))->withQueryString(),
        );
    }
```

The `assertApp` tenant guard stays first and untouched.

- [ ] **Step 4: Run the test to verify it passes**

Run: `php artisan test tests/Feature/Api/V1/IndexFilteringTest.php`
Expected: PASS.

- [ ] **Step 5: Commit**

```bash
git add app/Http/Controllers/Api/V1/BuildController.php tests/Feature/Api/V1/IndexFilteringTest.php
git commit -m "feat(api): filter and search the builds index by tags"
```

---

### Task 6: Credentials endpoint

**Files:**
- Modify: `app/Http/Controllers/Api/V1/CredentialController.php` — `index()` at lines 52-64
- Test: `tests/Feature/Api/V1/IndexFilteringTest.php`

**Interfaces:**
- Consumes: `AppliesFilters` (Task 4), `TagFilter`, `SearchFilter`.
- Produces: nothing new.

This `index()` already wraps a two-branch `whereHas`/`orWhereHas` in a closure to scope credentials to the app. That group must stay intact — appending filters after it is safe because `SearchFilter` groups its own OR.

- [ ] **Step 1: Write the failing test**

Add to `tests/Feature/Api/V1/IndexFilteringTest.php` (add `use App\Models\Credential;`):

```php
    public function test_credentials_can_be_filtered_by_tag(): void
    {
        [$user, $team, $app, $identifier] = $this->apiContext();

        $tagged = Credential::factory()->create(['team_id' => $team->id]);
        $tagged->appIdentifiers()->attach($identifier);
        $tagged->syncTags([['prod', null]])->save();

        $plain = Credential::factory()->create(['team_id' => $team->id]);
        $plain->appIdentifiers()->attach($identifier);

        $this->authenticateApi($user, $team, [ApiKeyScope::CredentialsRead]);

        $ids = collect($this->getJson($this->apiUrl($team, "/apps/{$app->slug}/credentials?tags[]=prod"))
            ->assertOk()->json('data'))->pluck('id');

        $this->assertTrue($ids->contains($tagged->id));
        $this->assertFalse($ids->contains($plain->id));
    }
```

Confirm the pivot attach signature first — `grep -n "function appIdentifiers" -A 6 app/Models/Credential.php`. If the pivot requires extra columns, pass them in the `attach()` call.

- [ ] **Step 2: Run the test to verify it fails**

Run: `php artisan test --filter test_credentials_can_be_filtered_by_tag`
Expected: FAIL — both credentials come back.

- [ ] **Step 3: Wire the controller**

Add the imports and trait as in Task 4 step 5, then replace `index()`:

```php
    public function index(Request $request, Team $team, MobileApp $mobileApp): AnonymousResourceCollection
    {
        $credentials = $this->filter(
            $request,
            $team->credentials()
                ->where(fn ($query) => $query
                    ->whereHas('appIdentifiers', fn ($relation) => $relation->where('mobile_app_id', $mobileApp->id))
                    ->orWhereHas('profiles.appIdentifier', fn ($relation) => $relation->where('mobile_app_id', $mobileApp->id)))
                ->with($this->relationsFor($mobileApp)),
            new SearchFilter(['name'], includeTags: true),
            new TagFilter(),
        );

        return CredentialResource::collection(
            $credentials->latest()->paginate($this->perPage($request))->withQueryString(),
        );
    }
```

- [ ] **Step 4: Run the test to verify it passes**

Run: `php artisan test tests/Feature/Api/V1/IndexFilteringTest.php tests/Feature/CredentialTest.php`
Expected: PASS.

- [ ] **Step 5: Commit**

```bash
git add app/Http/Controllers/Api/V1/CredentialController.php tests/Feature/Api/V1/IndexFilteringTest.php
git commit -m "feat(api): filter and search the credentials index by tags"
```

---

### Task 7: Artifacts endpoint

**Files:**
- Modify: `app/Http/Controllers/Api/V1/ArtifactController.php` — `index()` at lines 30-39
- Test: `tests/Feature/Api/V1/IndexFilteringTest.php`

**Interfaces:**
- Consumes: `AppliesFilters` (Task 4), `TagFilter`, `SearchFilter`.
- Produces: nothing new.

- [ ] **Step 1: Write the failing test**

Add to `tests/Feature/Api/V1/IndexFilteringTest.php` (add `use App\Models\Artifact;`):

```php
    public function test_artifacts_can_be_filtered_by_tag_and_searched_by_name(): void
    {
        [$user, $team, $app, $identifier] = $this->apiContext();
        $build = BuildRecord::factory()->create([
            'team_id' => $team->id,
            'mobile_app_id' => $app->id,
            'app_identifier_id' => $identifier->id,
        ]);

        $signed = Artifact::factory()->create([
            'team_id' => $team->id,
            'build_record_id' => $build->id,
            'name' => 'app-signed.ipa',
        ]);
        $signed->syncTags([['signed', null]])->save();

        $draft = Artifact::factory()->create([
            'team_id' => $team->id,
            'build_record_id' => $build->id,
            'name' => 'app-draft.apk',
        ]);

        $this->authenticateApi($user, $team, [ApiKeyScope::ArtifactsRead]);
        $path = "/apps/{$app->slug}/builds/{$build->id}/artifacts";

        $byTag = collect($this->getJson($this->apiUrl($team, $path.'?tags[]=signed'))
            ->assertOk()->json('data'))->pluck('id');
        $this->assertTrue($byTag->contains($signed->id));
        $this->assertFalse($byTag->contains($draft->id));

        $bySearch = collect($this->getJson($this->apiUrl($team, $path.'?search=draft'))
            ->assertOk()->json('data'))->pluck('id');
        $this->assertTrue($bySearch->contains($draft->id));
        $this->assertFalse($bySearch->contains($signed->id));
    }
```

- [ ] **Step 2: Run the test to verify it fails**

Run: `php artisan test --filter test_artifacts_can_be_filtered_by_tag_and_searched_by_name`
Expected: FAIL — both artifacts come back from both requests.

- [ ] **Step 3: Wire the controller**

Add the imports and trait as in Task 4 step 5, then replace `index()`:

```php
    public function index(Request $request, Team $team, MobileApp $mobileApp, BuildRecord $buildRecord): AnonymousResourceCollection
    {
        $this->assertBuildBelongs($team, $mobileApp, $buildRecord);

        $artifacts = $this->filter(
            $request,
            $buildRecord->artifacts()->with('media'),
            new SearchFilter(['name'], includeTags: true),
            new TagFilter(),
        );

        return ArtifactResource::collection(
            $artifacts->latest()->paginate($this->perPage($request))->withQueryString(),
        );
    }
```

- [ ] **Step 4: Run the test to verify it passes**

Run: `php artisan test tests/Feature/Api/V1/IndexFilteringTest.php`
Expected: PASS.

- [ ] **Step 5: Commit**

```bash
git add app/Http/Controllers/Api/V1/ArtifactController.php tests/Feature/Api/V1/IndexFilteringTest.php
git commit -m "feat(api): filter and search the artifacts index by tags"
```

---

### Task 8: Full verification and API documentation

**Files:**
- Modify: whichever document lists v1 query parameters — find it with
  `grep -rln "per_page" docs libs/cli/README.md`

- [ ] **Step 1: Run the full suite**

Run: `php artisan test`
Expected: PASS.

- [ ] **Step 2: Confirm the four endpoints behave identically without parameters**

Already asserted by `test_apps_index_without_filters_returns_everything` for apps. Confirm the other three are covered by the pre-existing API tests for those endpoints:

```bash
grep -rn "builds\"\|/credentials\|/artifacts" tests/Feature/Api/V1/*.php | grep -i "getJson\|assertOk" | head
```

If any of the three has no index test at all, add a no-parameter case to `IndexFilteringTest` mirroring the apps one.

- [ ] **Step 3: Document the new parameters**

Add `tags[]`, `tags_mode` and `search` to whatever reference lists v1 query parameters alongside `per_page`. If no such document exists, skip this step and say so — do not invent a new documentation file.

- [ ] **Step 4: Commit**

Stage the specific document you edited — never `git add -A`. This branch carries
unrelated staged and unstaged work (`BuildController.php`, the notification
integration files, `DemoSeeder.php`, two already-staged test files), and a
blanket `add` would commit all of it.

```bash
git add <the file edited in step 3>
git commit -m "docs(api): document the index filter parameters"
```

If step 3 found nothing to document, skip this commit entirely.

## Self-Review Notes

Spec coverage check against `2026-08-03-query-filters-api-design.md`:

- `Filter` interface, `FilterSet`, `AppliesFilters` → Tasks 1, 4
- `TagFilter` with `tags`/`tags_mode`, `LogicException` guard → Task 2
- `SearchFilter` with columns + `includeTags`, mandatory OR group → Task 3
- All four endpoint wirings with the exact search columns from the spec's table → Tasks 4-7
- `withQueryString()` pagination fix → Tasks 4-7, asserted in Task 4
- Every test in the spec's Testing section → Tasks 1-7
- 422 on unknown `tags_mode` → Task 4
- No-parameter regression → Task 4 step 7, Task 8 step 2

Out of scope, per the spec, and deliberately absent from this plan: web controller
migration, domain filters (`status`, `expo`, `missing_credentials`, platforms),
relation search through dotted paths in `SearchFilter`, echoing applied filters
back to Inertia, the tag filter UI, sorting, and `libs/cli` flags.

Two things the implementer should expect to resolve rather than assume:

1. `Filter::apply()` is typed `Builder`, but Tasks 4-7 pass relation instances
   (`$team->mobileApps()`, `$buildRecord->artifacts()`). Relations proxy `Builder`
   but are not `Builder`. If PHP rejects the argument, call `->getQuery()` on the
   relation at the call site — do not widen the interface to `mixed`.
2. Task 4 step 5 notes the same thing for `MobileAppController`. Resolve it once,
   the same way, in all four controllers.

Defects found and fixed during this plan's own self-review, noted so a reviewer
does not reintroduce them: a promoted constructor property cannot be declared by
reference (the `FilterSet` stub recorder uses `ArrayObject` instead), and the
pagination assertion must compare against the decoded link rather than guessing
Laravel's array encoding.

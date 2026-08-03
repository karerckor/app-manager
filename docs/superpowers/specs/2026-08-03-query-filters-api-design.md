# Query Filters — Abstraction and API Tag Filtering — Design

Date: 2026-08-03
Scope: `apps/platform`
Depends on: `2026-08-03-tags-flexible-shape-design.md` (the `whereAllTags` and
`whereTagLike` scopes)

## Goal

Give the v1 API index endpoints search and tag filtering, and introduce a filter
abstraction so that query-parameter handling stops being hand-rolled per
controller. Tag filtering matches on tag **text only** — color and animation are
never searchable.

Today the four taggable v1 index endpoints (`apps.index`, `apps.builds.index`,
`apps.credentials.index`, `artifacts.index`) accept no parameters beyond
`per_page`. Meanwhile five web controllers each rebuild their own `search`/`when`
chains, and `DashboardController::index` is roughly 140 lines of query building.
This spec builds the abstraction and proves it on the API. Migrating the web
controllers onto it is the follow-up spec.

## The abstraction

Three small pieces. The organizing idea: **a filter owns its request keys** — both
the validation rules for them and the effect they have on the query. A controller
declares which filters an endpoint accepts and nothing else.

### `App\Support\Filters\Filter`

```php
interface Filter
{
    /**
     * Validation rules for the request keys this filter owns.
     *
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array;

    /**
     * @param  array<string, mixed>  $data  Validated request data.
     */
    public function apply(Builder $query, array $data): void;
}
```

`apply()` receives the whole validated payload rather than just its own slice,
because a filter may legitimately own more than one key (`TagFilter` owns both
`tags` and `tags_mode`) and reading them from one array keeps the signature stable.

A filter with no applicable input returns without touching the query. Deciding "am
I active?" belongs to the filter, not the caller — that is what removes the
`->when($x !== [], ...)` noise from controllers.

### `App\Support\Filters\FilterSet`

```php
final class FilterSet
{
    /** @param list<Filter> $filters */
    public function __construct(private readonly array $filters) {}

    /** @return array<string, array<int, mixed>> */
    public function rules(): array;

    /** @param array<string, mixed> $data */
    public function apply(Builder $query, array $data): Builder;
}
```

`rules()` merges the members' rules. `apply()` calls each member in declaration
order and returns the builder for chaining.

### `App\Http\Concerns\AppliesFilters`

Lives beside `ResolvesPageSize`, the existing precedent for a thin controller
concern:

```php
trait AppliesFilters
{
    protected function filter(Request $request, Builder $query, Filter ...$filters): Builder
    {
        $set = new FilterSet($filters);

        return $set->apply($query, $request->validate($set->rules()));
    }
}
```

Validation stays outside `apply()` and goes through `$request->validate()`, so
failures surface as the same 422 the rest of the API produces and the codebase's
inline-validation style is preserved. Rules are assembled from the filters instead
of being typed out per controller.

## Concrete filters

### `App\Support\Filters\TagFilter`

Owns `tags` and `tags_mode`.

```
tags       nullable, array, max:50
tags.*     string, max:TagRules::MAX_TEXT_LENGTH
tags_mode  nullable, in:any,all
```

`apply()` trims entries and drops empty ones; if nothing remains it is a no-op.
Otherwise `tags_mode=all` dispatches to `whereAllTags()` and anything else — the
default included — to `whereAnyTag()`. Matching is exact on tag text, using the
existing `applyTagPredicate` SQL.

`tags_mode` defaults to `any` because that is what `whereAnyTag` already does for
the web pages; the parameter exists so a UI can offer "has all of these" without
a second endpoint.

If the query's model does not use `HasTags`, `apply()` throws `LogicException`.
This is a wiring mistake by a developer, and failing loudly beats silently
returning an unfiltered or empty set.

### `App\Support\Filters\SearchFilter`

```php
public function __construct(
    private readonly array $columns,
    private readonly bool $includeTags = false,
)
```

Owns `search` (`nullable`, `string`, `max:100`). `apply()` wraps a grouped `OR` —
`whereLike` on each declared column, plus `orWhereTagLike($pattern)` when
`includeTags` is set. The grouping matters: without it the `OR` would escape and
defeat any other filter or tenant scope on the query.

Only tag text participates. `whereTagLike` reads `[0]` of each tuple, so a search
for `pulse` never matches a tag whose *animation* is `pulse`, and a search for
`#f00` never matches a color. This holds on PostgreSQL and SQLite, the drivers this
project supports; see the note on `whereTagLike`'s raw-JSON fallback in the tags
spec.

Column paths are plain columns on the queried model in this spec. Relation search
through dotted paths (`appIdentifiers.identifier`) is what the web controllers
need and is added to this same class in the follow-up spec — an extension of
`apply()`, not a change to the interface.

## Endpoint wiring

Each of the four index methods gains `use AppliesFilters` and one `filter()` call,
with `includeTags: true` throughout:

| endpoint | search columns |
|---|---|
| `Api/V1/MobileAppController::index` | `name`, `slug`, `description` |
| `Api/V1/BuildController::index` | `request_uuid`, `expo_build_id` |
| `Api/V1/CredentialController::index` | `name` |
| `Api/V1/ArtifactController::index` | `name` |

`BuildRecord` has no `name` column; per its `#[Fillable]` attribute the free-text
identifiers are `request_uuid` and `expo_build_id`. Its `status` and `profile_type`
are enums and belong in dedicated filters, which arrive with the web migration.

Example shape:

```php
$builds = $this->filter(
    $request,
    $mobileApp->buildRecords()->with('ciCdRun'),
    new SearchFilter(['request_uuid', 'expo_build_id'], includeTags: true),
    new TagFilter(),
)->latest()->paginate($this->perPage($request, default: 50))->withQueryString();
```

Filters are applied before `latest()` and pagination. The existing tenant guards
(`assertApp`, `assertBuildBelongs`, the `$team->credentials()` relation root) run
unchanged and are untouched by filtering — filters compose onto an
already-scoped builder.

### Pagination fix

These four endpoints currently call `paginate()` without `withQueryString()`, so
the generated `next`/`prev` links drop every query parameter except `page`. That is
invisible today because no parameters exist; it becomes a real bug the moment
filters ship. `withQueryString()` is added to all four. The web controllers already
do this.

## Request/response contract

```
GET /apps?tags[]=beta&tags[]=qa            has beta OR qa
GET /apps?tags[]=beta&tags[]=qa&tags_mode=all   has beta AND qa
GET /apps?search=onb                       name/slug/description LIKE %onb%
                                           OR tag text LIKE %onb%
```

Every parameter is optional and combinable; parameters combine with `AND` between
filters. Absent parameters reproduce today's behavior exactly, so this is not a
breaking change and the existing API test suite stays green.

Response bodies are unchanged — same resources, same pagination envelope. Nothing
echoes the applied filters back; the web controllers need that for their Inertia
`filters` payload and it arrives with them in the follow-up spec.

## Error handling

- Unknown `tags_mode` → 422 with a field error on `tags_mode`.
- `tags` not an array, over 50 entries, or an entry over 50 chars → 422.
- `search` over 100 chars → 422.
- A filter wired onto a model without `HasTags` → `LogicException` (500). A
  developer error, caught by the endpoint's own test on first run.

## Testing

Unit, against the filters directly:

- `TagFilter` in `any` and `all` modes; whitespace-only and empty arrays are no-ops;
  `tags_mode` absent behaves as `any`.
- `SearchFilter` matches each declared column, matches tag text when
  `includeTags`, and does not match tag color or animation values.
- `SearchFilter`'s `OR` group does not leak — a `search` combined with a `tags`
  filter narrows rather than widens the result set.
- `FilterSet::rules()` merges without collisions; `apply()` runs members in order.

Feature, per endpoint:

- Filter by a single tag; by two tags with `OR`; by two tags with `tags_mode=all`.
- `search` matching a record only through its tag text, not its own columns.
- Tenant isolation: a tag on another team's record does not leak the record.
- Pagination links preserve `tags[]`, `tags_mode` and `search`.
- Invalid `tags_mode` returns 422.
- No parameters returns the same set and order as before the change.

## Out of scope

- Migrating the web controllers (`DashboardController`, `CredentialVaultController`,
  `AppBuildPageController`, `Integrations/IntegrationIndexController`,
  `PushNotificationToolController`) onto the abstraction, the domain filters that
  requires (`status`, `expo`, `missing_credentials`, platform filters), relation
  search in `SearchFilter`, echoing applied filters back to Inertia, and the tag
  filter UI. That is the third spec in this sequence.
- Sorting as a filter-like abstraction.
- Filter flags in `libs/cli`.
- Filtering on `notification_destinations`, which has no v1 index endpoint.

## Branch note

`feat/credentials-byov` currently carries uncommitted work unrelated to tags,
including a modified `Api/V1/BuildController` and a new
`tests/Feature/Api/V1/BuildTagsApiTest.php`. This spec edits
`BuildController::index`, so expect to reconcile with that in-flight work.

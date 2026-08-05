# API build-context implementation plan

**Date:** 2026-07-17
**Milestone:** 1 of the multi-platform demos roadmap
**Status:** Implemented and regression-tested
**Goal:** Establish a deterministic, tested API contract for one platform build
before changing the Fastlane plugin or adding more demos.

## Outcome

After this milestone, a client can request one app/platform/profile and receive
all app metadata, target versioning, assets and compatible credentials in one
response. The request never infers a target from alphabetical ordering.

The milestone also provides two white-label apps and a multi-target fixture so
later plugin/demo work can be tested without inventing new data.

## In scope

- `AppIdentifier.build_number` for iOS.
- `AppIdentifier.is_default` with deterministic selection rules.
- `GET /api/v1/{team}/apps/{app}/build-context`.
- Exact JSON contract and scoping tests.
- Two white-label demo apps and at least one non-default target in
  `DemoSeeder`.
- API documentation.

## Out of scope

- Fastlane plugin changes.
- Refactoring `demos/expo-hello-world`.
- New icon/splash variants.
- Real store deployment.
- Broad token/ACL redesign. This milestone preserves existing read access and
  adds regression tests so the new endpoint cannot expose data more broadly
  than the current credential API.

## Dependencies and coordination

The working branch already contains ongoing team ACL changes touching API
controllers and tests. Implement this milestone after those changes are stable
or rebase it carefully. Do not overwrite unrelated ACL work.

The canonical profile and credential definitions remain:

- `App\Support\Credentials\BuildProfileSchema`;
- `App\Support\Credentials\CredentialSchema`;
- `App\Http\Resources\Api\V1\CredentialResource`.

The build-context response must reuse them rather than duplicating profile or
credential serialization.

## Proposed files

### Create

- `apps/platform/database/migrations/2026_07_17_020000_add_build_fields_to_app_identifiers_table.php`
- `apps/platform/app/Support/Builds/BuildContextResolver.php`
- `apps/platform/app/Http/Controllers/Api/V1/BuildContextController.php`
- `apps/platform/tests/Feature/Api/V1/BuildContextApiTest.php`

### Modify

- `apps/platform/app/Models/AppIdentifier.php`
- `apps/platform/app/Http/Controllers/Api/V1/AppIdentifierController.php`
- `apps/platform/app/Http/Resources/Api/V1/AppIdentifierResource.php`
- `apps/platform/routes/api.php`
- `apps/platform/database/seeders/DemoSeeder.php`
- `apps/platform/tests/Feature/DemoSeederTest.php`
- `apps/platform/tests/Feature/Api/V1/PlatformApiTest.php`
- `apps/platform/docs/api-v1.md`

The final implementation may introduce a dedicated build-context resource or
form request if that makes the controller smaller. Do not put selection and
query logic directly into the resource serializer.

---

## Task 1 — lock the identifier field contract with failing tests

**Tests:**

- `apps/platform/tests/Feature/Api/V1/PlatformApiTest.php`
- optionally a focused identifier unit/feature test if the API test becomes too
  large.

- [ ] Add an API test that creates an iOS identifier with `version=2.3.0` and
  `build_number=20300`, then verifies both fields in create, show and index
  responses.
- [ ] Add a test proving that `build_number` is prohibited for Android.
- [ ] Add a test proving that `version_code` remains prohibited for iOS.
- [ ] Add a test that the first identifier for an app/platform becomes default.
- [ ] Add a second identifier and explicitly move the default flag to it.
- [ ] Verify that the previous target is no longer default.
- [ ] Add a validation test preventing two defaults for the same app/platform,
  including a concurrent/transactional path where practical.
- [ ] Run the focused tests and confirm that they fail because the fields and
  behavior do not exist yet.

**Acceptance:** Tests describe the public field names and default-selection
behavior before the migration is written.

---

## Task 2 — add `build_number` and `is_default`

**Files:** migration, model, identifier controller and resource.

- [ ] Add nullable string `build_number` and boolean `is_default` defaulting to
  false.
- [ ] Add a partial unique index for default identifiers on
  `(mobile_app_id, platform)` where `is_default = true`. The migration must work
  on PostgreSQL and the SQLite test database; use explicit driver-specific SQL
  only if Laravel schema helpers cannot express the index.
- [ ] Add both fields to `AppIdentifier` fillable/casts.
- [ ] Extend create/update validation:
  - `build_number`: nullable string, maximum 255, iOS-only;
  - `version_code`: unchanged and Android-only;
  - `is_default`: boolean.
- [ ] Perform default changes inside a database transaction. Setting one target
  default clears the flag from the other targets of that app/platform before
  saving.
- [ ] When the first identifier for a platform is created, make it default even
  if the caller omits `is_default`.
- [ ] Do not select a replacement silently when a default target is deleted and
  multiple alternatives remain. A later request without an explicit identifier
  must fail as ambiguous until the user chooses a new default.
- [ ] When changing an identifier's platform, reject a move that would create a
  second default on the destination platform.
- [ ] Return `build_number` and `is_default` through
  `AppIdentifierResource`.
- [ ] Order identifier indexes by platform, default first, then name/id for
  stable presentation. Ordering must not define default semantics.
- [ ] Run the focused tests until they pass.

**Acceptance:** The database and API enforce at most one declared default per
app/platform, and iOS build numbers round-trip through API v1.

---

## Task 3 — write failing build-context selection tests

**Create:** `apps/platform/tests/Feature/Api/V1/BuildContextApiTest.php`.

Build reusable test fixtures for one team/app containing:

- two Android identifiers, one default;
- one iOS identifier;
- Android production and preview profile credentials;
- iOS App Store profile credentials;
- identifier-scoped Firebase/store credentials;
- a credential belonging to another app and another team.

Add failing tests for:

- [ ] authentication is required;
- [ ] a non-member cannot read the context;
- [ ] `platform` and `profile_type` are required;
- [ ] unsupported platform/profile combinations return `422`;
- [ ] explicit identifier selection works;
- [ ] omitted identifier selects the declared default;
- [ ] exactly one compatible identifier works even when no default is set;
- [ ] multiple identifiers without a default return `422` with an actionable
  error;
- [ ] an identifier from another app returns `404` or the existing scoped
  equivalent without revealing it;
- [ ] only credentials attached to the selected identifier/profile appear;
- [ ] credentials from another profile, app or team do not appear;
- [ ] app assets use authenticated API download URLs;
- [ ] credential file metadata and payload use the existing
  `CredentialResource` shape.

Add one exact top-level JSON assertion covering every build-context field.
Prefer explicit `assertJsonPath`/`assertExactJson` expectations over snapshot
text that can be updated without review.

**Acceptance:** Selection, scoping and response shape are completely specified
before implementing the endpoint.

---

## Task 4 — implement `BuildContextResolver`

**Create:** `apps/platform/app/Support/Builds/BuildContextResolver.php`.

The resolver accepts the already route-scoped `MobileApp`, platform,
profile type and optional identifier string.

- [ ] Validate platform through `Platform` and profile through `ProfileType`.
- [ ] Validate the profile/platform pair with `BuildProfileSchema`.
- [ ] Select the target using this precedence:
  1. exact explicit identifier;
  2. declared default;
  3. the only identifier for the platform;
  4. validation error listing the compatible identifiers.
- [ ] Resolve the database profile if it exists, while always returning the
  schema definition even if the profile row does not exist.
- [ ] Load direct identifier credentials plus credentials attached to the
  selected profile only.
- [ ] Eager-load media and relationships required by
  `CredentialResource`; the endpoint must not introduce N+1 queries.
- [ ] Return credentials in deterministic order: type, name, id.
- [ ] Keep all queries scoped through the selected app/team relationships.
- [ ] Unit-test the resolver only if behavior cannot be clearly covered through
  the API feature tests; do not duplicate the same assertions at two levels.

**Acceptance:** The resolver contains no HTTP response formatting and can be
called later by another API surface if needed.

---

## Task 5 — add the build-context route and response

**Files:** controller, routes and optionally a dedicated resource.

- [ ] Add the named route under the existing authenticated/team-scoped API v1
  group:

```text
api.v1.apps.build-context.show
```

- [ ] Validate query parameters without accepting arbitrary extra selectors.
- [ ] Reuse `MobileAppResource` asset serialization or extract a shared asset
  serializer; do not hand-build a second incompatible asset shape.
- [ ] Reuse `CredentialResource` for every credential.
- [ ] Return `schema_version: 1` as an integer.
- [ ] Return profile definition fields `value`, `platform`, `label`, `artifact`
  and `description`, plus nullable database `id` and `name` if present.
- [ ] Preserve the same authorization behavior as reading the app and its
  credentials separately.
- [ ] Add regression tests for the current ACL roles/API key behavior. If the
  ACL branch introduces a dedicated credential-read permission, require it
  here as well.
- [ ] Make every test from Task 3 pass.

**Acceptance:** One authenticated request provides the complete, scoped input
needed for a platform build without selecting a credential candidate silently.

---

## Task 6 — seed white-label and multi-target fixtures

**Files:** `DemoSeeder.php`, `DemoSeederTest.php`.

Preserve the existing `example-app` slug and demo API key so current workflows
continue to work.

- [ ] Populate versions on the current app:
  - version `1.0.0`;
  - Android version code `1`;
  - iOS build number `1`;
  - current Android/iOS identifiers marked default.
- [ ] Add a non-default beta target to at least one platform with a distinct
  identifier and build credential.
- [ ] Add `example-app-blue` as a second `MobileApp` representing another
  white-label brand.
- [ ] Give the blue brand distinct name, Android package, iOS bundle id, icon,
  splash, Firebase client files and signing credentials.
- [ ] Give store credentials distinct filenames/content so tests can prove that
  a brand did not receive the other brand's file.
- [ ] Refactor certificate/provisioning/Firebase helper methods to accept the
  identifier and brand-specific values instead of relying on one global bundle
  id constant.
- [ ] Keep the seeder idempotent. Seed twice and assert stable counts,
  attachments and media rows.
- [ ] Add tests calling build-context for both apps and comparing the target and
  credential ids.

**Acceptance:** The database contains enough realistic data to test independent
target selection and white-label isolation without manual setup.

---

## Task 7 — document the contract

**Modify:** `apps/platform/docs/api-v1.md`.

- [ ] Document `build_number` and `is_default` in identifier create/update/read
  examples.
- [ ] Document default-selection behavior and ambiguity errors.
- [ ] Add the build-context endpoint, query parameters and a complete response
  example with secrets replaced by obvious placeholders.
- [ ] Explain that `credentials` contains candidates and that clients must use
  an explicit id/name when more than one credential of a required type exists.
- [ ] Document that one `MobileApp` is one distributable brand and white-label
  pipelines select between app slugs.
- [ ] List every credential type currently returned by `/credential-types`,
  including both client Firebase files.
- [ ] Ensure documented expiration behavior matches the current
  credential-expiration implementation.

**Acceptance:** A plugin author can implement the next milestone using only the
API document and its JSON examples.

---

## Task 8 — verification and handoff

Run from `apps/platform`:

```bash
php artisan test tests/Feature/Api/V1/BuildContextApiTest.php
php artisan test tests/Feature/Api/V1/PlatformApiTest.php
php artisan test tests/Feature/DemoSeederTest.php
php artisan test
```

Then from the repository root:

```bash
git diff --check
```

- [ ] All focused and full Platform tests pass.
- [ ] Seed twice against a clean local database without duplicate rows/media.
- [ ] Use the seeded API key to request Android and iOS build-context for both
  brands.
- [ ] Confirm explicit beta selection and default selection return different
  target ids.
- [ ] Confirm a credential file URL downloads with the same Bearer token.
- [ ] Confirm an invalid team/app/identifier cannot reveal resource existence.
- [ ] Capture one sanitized response in the milestone handoff for the Fastlane
  plugin contract fixture.

## Suggested commit sequence

1. `test(api): define identifier build field contract`
2. `feat(api): add build number and default identifiers`
3. `test(api): define build context contract`
4. `feat(api): expose scoped build context`
5. `test(seed): cover white-label build fixtures`
6. `feat(seed): add white-label and multi-target demo data`
7. `docs(api): document build context`

Do not combine plugin or Expo changes into these commits. The next milestone
should begin from the sanitized build-context response produced here.

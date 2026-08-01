<?php

namespace Tests\Feature\Audit;

use App\Enums\TeamRole;
use App\Models\AppIdentifier;
use App\Models\Audit;
use App\Models\BuildRecord;
use App\Models\MobileApp;
use App\Models\Team;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class AuditLogPageTest extends TestCase
{
    use RefreshDatabase;

    private function owner(): array
    {
        $user = User::factory()->create();
        $team = Team::factory()->create(['owner_id' => $user->id]);
        $team->users()->attach($user, ['role' => 'owner']);
        $user->update(['current_team_id' => $team->id]);

        return [$user->fresh(), $team];
    }

    public function test_owner_sees_team_scoped_audits(): void
    {
        [$user, $team] = $this->owner();
        // MobileApp is itself Auditable, so creating it via the factory would
        // record its own "created" audit and inflate the count below. Disable
        // auditing for this setup step; only the audits created explicitly
        // in this test should be counted.
        MobileApp::disableAuditing();
        $app = MobileApp::factory()->create(['team_id' => $team->id]);
        MobileApp::enableAuditing();
        Audit::create([
            'user_type' => User::class, 'user_id' => $user->id, 'event' => 'created',
            'auditable_type' => MobileApp::class, 'auditable_id' => $app->id,
            'old_values' => [], 'new_values' => [
                'name' => 'Acme',
                'tags' => '[["Imported from Expo","#6366f1"],["Metadata only","#f59e0b"]]',
            ],
            'team_id' => $team->id, 'source' => 'web',
        ]);
        $other = Team::factory()->create();
        Audit::create([
            'event' => 'created', 'auditable_type' => MobileApp::class, 'auditable_id' => $app->id,
            'old_values' => [], 'new_values' => [], 'team_id' => $other->id, 'source' => 'web',
        ]);

        $this->actingAs($user)->get(route('accounts.audit.index', $team))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('AuditLog/Index')
                ->has('audits.data', 1)
                ->where('audits.data.0.action_label', 'Created')
                ->where('audits.data.0.target.label', $app->name)
                ->where('audits.data.0.target.type', 'App')
                ->where('audits.data.0.target.href', route('accounts.apps.show', [$team, $app]))
                ->where('audits.data.0.changes.0.field', 'Name')
                ->where('audits.data.0.changes.0.to', 'Acme')
                ->where('audits.data.0.changes.1', [
                    'key' => 'tags',
                    'field' => 'Tags',
                    'mode' => 'added',
                    'from' => null,
                    'to' => '2 tags',
                    'from_tags' => [],
                    'to_tags' => [
                        ['label' => 'Imported from Expo', 'color' => '#6366f1'],
                        ['label' => 'Metadata only', 'color' => '#f59e0b'],
                    ],
                ])
                ->where('audits.data.0.source_label', 'Web'));
    }

    public function test_entries_include_specific_build_links_changes_and_request_context(): void
    {
        [$user, $team] = $this->owner();

        MobileApp::disableAuditing();
        AppIdentifier::disableAuditing();
        BuildRecord::disableAuditing();
        try {
            $app = MobileApp::factory()->create(['team_id' => $team->id, 'name' => 'Acme Wallet']);
            $identifier = AppIdentifier::factory()->create([
                'mobile_app_id' => $app->id,
                'name' => 'iPhone',
                'identifier' => 'com.acme.wallet',
            ]);
            $build = BuildRecord::factory()->create([
                'team_id' => $team->id,
                'mobile_app_id' => $app->id,
                'app_identifier_id' => $identifier->id,
                'requested_by_user_id' => $user->id,
            ]);
        } finally {
            MobileApp::enableAuditing();
            AppIdentifier::enableAuditing();
            BuildRecord::enableAuditing();
        }

        Audit::create([
            'user_type' => User::class,
            'user_id' => $user->id,
            'event' => 'updated',
            'auditable_type' => BuildRecord::class,
            'auditable_id' => $build->id,
            'old_values' => ['status' => 'running', 'log_output' => 'do not expose this'],
            'new_values' => ['status' => 'completed', 'log_output' => 'or this'],
            'team_id' => $team->id,
            'source' => 'ci_cd',
            'ip_address' => '192.0.2.10',
            'user_agent' => 'App Manager Runner/1.0',
        ]);

        $this->actingAs($user)
            ->get(route('accounts.audit.index', $team))
            ->assertOk()
            ->assertDontSee('do not expose this')
            ->assertDontSee('or this')
            ->assertInertia(fn (Assert $page) => $page
                ->where('audits.data.0.action_label', 'Status changed')
                ->where('audits.data.0.target.label', 'Build #'.substr($build->id, 0, 8))
                ->where('audits.data.0.target.meta', 'Acme Wallet · iPhone')
                ->where('audits.data.0.target.href', route('accounts.apps.builds.show', [$team, $app, $build]))
                ->where('audits.data.0.changes', [[
                    'key' => 'status',
                    'field' => 'Status',
                    'mode' => 'changed',
                    'from' => 'Running',
                    'to' => 'Completed',
                ]])
                ->where('audits.data.0.source_label', 'CI/CD')
                ->where('audits.data.0.ip_address', '192.0.2.10')
                ->where('audits.data.0.user_agent', 'App Manager Runner/1.0'));
    }

    public function test_non_owner_member_is_forbidden(): void
    {
        [, $team] = $this->owner();
        $member = User::factory()->create();
        $team->users()->attach($member, ['role' => 'member']);
        $member->update(['current_team_id' => $team->id]);

        $this->actingAs($member->fresh())->get(route('accounts.audit.index', $team))->assertForbidden();
    }

    public function test_admin_can_view_the_audit_log(): void
    {
        [, $team] = $this->owner();
        $admin = User::factory()->create();
        $team->addUser($admin, TeamRole::Admin);
        $admin->update(['current_team_id' => $team->id]);

        $this->actingAs($admin->fresh())
            ->get(route('accounts.audit.index', $team))
            ->assertOk();
    }

    public function test_audits_can_be_filtered_by_multiple_apps_actors_events_sources_and_dates(): void
    {
        [$owner, $team] = $this->owner();
        $actor = User::factory()->create(['name' => 'Ada']);
        $secondActor = User::factory()->create(['name' => 'Lin']);
        $team->addUser($actor, TeamRole::Contributor);
        $team->addUser($secondActor, TeamRole::Contributor);

        MobileApp::disableAuditing();
        AppIdentifier::disableAuditing();
        BuildRecord::disableAuditing();
        try {
            $selectedApp = MobileApp::factory()->create(['team_id' => $team->id, 'name' => 'Selected']);
            $otherApp = MobileApp::factory()->create(['team_id' => $team->id, 'name' => 'Other']);
            $selectedIdentifier = AppIdentifier::factory()->create(['mobile_app_id' => $selectedApp->id]);
            $selectedBuild = BuildRecord::factory()->create([
                'team_id' => $team->id,
                'mobile_app_id' => $selectedApp->id,
                'app_identifier_id' => $selectedIdentifier->id,
                'requested_by_user_id' => $actor->id,
            ]);
        } finally {
            MobileApp::enableAuditing();
            AppIdentifier::enableAuditing();
            BuildRecord::enableAuditing();
        }

        foreach ([
            [$selectedApp, $actor, 'updated', 'web', '2026-07-10'],
            [$selectedIdentifier, $actor, 'updated', 'api', '2026-07-11'],
            [$selectedBuild, $secondActor, 'created', 'ci_cd', '2026-07-12'],
            [$selectedApp, $owner, 'created'],
            [$otherApp, $actor, 'updated'],
        ] as $row) {
            [$auditable, $user, $event] = $row;
            $source = $row[3] ?? 'web';
            $createdAt = $row[4] ?? null;
            $audit = Audit::create([
                'user_type' => User::class,
                'user_id' => $user->id,
                'event' => $event,
                'auditable_type' => $auditable::class,
                'auditable_id' => $auditable->id,
                'old_values' => [],
                'new_values' => [],
                'team_id' => $team->id,
                'source' => $source,
            ]);

            if ($createdAt) {
                $audit->forceFill(['created_at' => $createdAt, 'updated_at' => $createdAt])->save();
            }
        }

        $this->actingAs($owner)
            ->get(route('accounts.audit.index', [
                'team' => $team,
                'apps' => [$selectedApp->id],
                'actors' => [$actor->id, $secondActor->id],
                'events' => ['created', 'updated'],
                'sources' => ['api', 'ci_cd'],
                'from' => '2026-07-11',
                'to' => '2026-07-12',
            ]))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->has('audits.data', 2)
                ->where('audits.data', fn ($rows) => collect($rows)->pluck('auditable_id')->sort()->values()->all() === collect([
                    $selectedIdentifier->id,
                    $selectedBuild->id,
                ])->sort()->values()->all())
                ->where('filters.apps', [$selectedApp->id])
                ->where('filters.actors', [$actor->id, $secondActor->id])
                ->where('filters.events', ['created', 'updated'])
                ->where('filters.sources', ['api', 'ci_cd'])
                ->where('filters.from', '2026-07-11')
                ->where('filters.to', '2026-07-12')
                ->has('filterOptions.apps', 2)
                ->has('filterOptions.users', 3)
                ->where('filterOptions.events', ['created', 'updated'])
                ->where('filterOptions.sources', ['api', 'ci_cd', 'web']));
    }

    public function test_non_member_is_forbidden(): void
    {
        [, $team] = $this->owner();
        $stranger = User::factory()->create();

        $this->actingAs($stranger)->get(route('accounts.audit.index', $team))->assertForbidden();
    }
}

<?php

namespace Tests\Feature;

use App\Actions\Builds\CreateManualBuild;
use App\Billing\BillingConfiguration;
use App\Billing\CashierMeterEventReporter;
use App\Billing\Contracts\AuthorizesBuildCreation;
use App\Billing\Contracts\ReportsMeterEvents;
use App\Enums\ProfileType;
use App\Jobs\Billing\ReportBillingUsage;
use App\Models\BillingUsageEvent;
use App\Models\MobileApp;
use App\Models\Team;
use App\Models\User;
use DateTimeInterface;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia as Assert;
use LogicException;
use Mockery;
use Stripe\Subscription as StripeSubscription;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\TestCase;

class BillingIntegrationTest extends TestCase
{
    use RefreshDatabase;

    public function test_self_hosted_distribution_has_no_billing_surface_or_usage_events(): void
    {
        config()->set([
            'billing.distribution' => 'self-hosted',
            'billing.mode' => 'off',
            'billing.enabled' => false,
        ]);

        [$owner, $team] = $this->team();
        $app = MobileApp::factory()->create(['team_id' => $team->id]);

        $this->actingAs($owner)
            ->get(route('accounts.billing.index', $team))
            ->assertNotFound();

        app(CreateManualBuild::class)->handle(
            $team,
            $app,
            $owner,
            (string) Str::uuid(),
            ProfileType::Production,
        );

        $this->assertDatabaseCount('billing_usage_events', 0);
    }

    public function test_hosted_billing_page_is_only_available_to_the_team_owner(): void
    {
        $this->enableHostedBilling();
        [$owner, $team] = $this->team();
        $member = User::factory()->create();
        $team->addUser($member, 'admin');

        $this->actingAs($owner)
            ->get(route('accounts.billing.index', $team))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Billing/Index')
                ->where('subscription.status', 'none')
                ->where('subscription.active', false)
                ->where('usage.build_runs_this_month', 0));

        $this->actingAs($member)
            ->get(route('accounts.billing.index', $team))
            ->assertForbidden();
    }

    public function test_hosted_builds_require_an_active_subscription(): void
    {
        $this->enableHostedBilling();
        [, $team] = $this->team();

        try {
            app(AuthorizesBuildCreation::class)->authorize($team);
            $this->fail('An unsubscribed hosted team was allowed to create a build.');
        } catch (HttpException $exception) {
            $this->assertSame(402, $exception->getStatusCode());
        }
    }

    public function test_live_billing_is_rejected_outside_production(): void
    {
        $this->enableHostedBilling();
        config()->set('billing.mode', 'live');

        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('Live billing mode may only be used by hosted production.');

        BillingConfiguration::assertReady();
    }

    public function test_each_hosted_build_creates_one_idempotent_usage_event(): void
    {
        $this->enableHostedBilling();
        Queue::fake();

        [$owner, $team] = $this->team();
        $this->subscribe($team);
        $app = MobileApp::factory()->create(['team_id' => $team->id]);
        $requestUuid = (string) Str::uuid();

        $build = app(CreateManualBuild::class)->handle(
            $team,
            $app,
            $owner,
            $requestUuid,
            ProfileType::Production,
        );

        app(CreateManualBuild::class)->handle(
            $team,
            $app,
            $owner,
            $requestUuid,
            ProfileType::Production,
        );

        $this->assertDatabaseCount('billing_usage_events', 1);
        $this->assertDatabaseHas('billing_usage_events', [
            'team_id' => $team->id,
            'event_key' => "build_run:{$build->id}",
            'meter_event_name' => 'app_manager_build_runs',
            'quantity' => 1,
        ]);
        Queue::assertPushed(ReportBillingUsage::class, 1);
    }

    public function test_usage_job_reports_the_persisted_identifier_and_marks_the_event(): void
    {
        $this->enableHostedBilling();
        [, $team] = $this->team();
        $team->forceFill(['stripe_id' => 'cus_test_team'])->save();
        $occurredAt = now()->subMinute();
        $event = BillingUsageEvent::create([
            'team_id' => $team->id,
            'event_key' => 'build_run:123',
            'meter_event_name' => 'app_manager_build_runs',
            'quantity' => 1,
            'occurred_at' => $occurredAt,
            'next_attempt_at' => now(),
        ]);

        $reporter = new class implements ReportsMeterEvents
        {
            public array $calls = [];

            public function report(
                Team $team,
                string $eventName,
                int $quantity,
                string $identifier,
                DateTimeInterface $occurredAt,
            ): void {
                $this->calls[] = compact('team', 'eventName', 'quantity', 'identifier', 'occurredAt');
            }
        };

        (new ReportBillingUsage($event->id))->handle($reporter);

        $this->assertCount(1, $reporter->calls);
        $this->assertSame('app_manager_build_runs', $reporter->calls[0]['eventName']);
        $this->assertSame('build_run:123', $reporter->calls[0]['identifier']);
        $this->assertSame(1, $reporter->calls[0]['quantity']);
        $this->assertNotNull($event->fresh()->reported_at);
        $this->assertSame(1, $event->fresh()->attempts);
    }

    public function test_cashier_reporter_reuses_the_event_identifier_as_an_idempotency_key(): void
    {
        $team = Mockery::mock(Team::class);
        $occurredAt = Carbon::parse('2026-01-15 12:00:00', 'Europe/Zagreb');

        $team->shouldReceive('reportMeterEvent')
            ->once()
            ->with(
                'app_manager_build_runs',
                1,
                [
                    'identifier' => 'build_run:123',
                    'timestamp' => '2026-01-15T11:00:00Z',
                ],
                ['idempotency_key' => 'build_run:123'],
            );

        (new CashierMeterEventReporter)->report(
            $team,
            'app_manager_build_runs',
            1,
            'build_run:123',
            $occurredAt,
        );
    }

    private function enableHostedBilling(): void
    {
        config()->set([
            'billing.distribution' => 'hosted',
            'billing.mode' => 'test',
            'billing.enabled' => true,
            'billing.prices.base_monthly' => 'price_base_test',
            'billing.prices.build_runs' => 'price_builds_test',
            'billing.meters.build_runs.id' => 'mtr_builds_test',
            'billing.meters.build_runs.event_name' => 'app_manager_build_runs',
            'cashier.key' => 'pk_test_example',
            'cashier.secret' => 'sk_test_example',
            'cashier.webhook.secret' => 'whsec_test_example',
        ]);
    }

    private function subscribe(Team $team): void
    {
        $team->forceFill(['stripe_id' => 'cus_test_team'])->save();
        $team->subscriptions()->create([
            'type' => 'default',
            'stripe_id' => 'sub_test_team',
            'stripe_status' => StripeSubscription::STATUS_ACTIVE,
            'stripe_price' => null,
            'quantity' => null,
        ]);
    }

    /**
     * @return array{User, Team}
     */
    private function team(): array
    {
        $owner = User::factory()->create();
        $team = Team::factory()->create(['owner_id' => $owner->id]);
        $team->addUser($owner, 'owner');
        $owner->update(['current_team_id' => $team->id]);

        return [$owner, $team];
    }
}

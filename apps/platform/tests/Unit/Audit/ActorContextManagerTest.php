<?php

namespace Tests\Unit\Audit;

use App\Models\ApiKey;
use App\Models\Team;
use App\Models\User;
use App\Support\Audit\ActorContext;
use App\Support\Audit\ActorContextManager;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Laravel\Sanctum\TransientToken;
use Tests\TestCase;

class ActorContextManagerTest extends TestCase
{
    use RefreshDatabase;

    public function test_defaults_to_system(): void
    {
        $manager = new ActorContextManager;

        $this->assertSame('system', $manager->current()->source);
    }

    public function test_run_as_restores_previous_context(): void
    {
        $manager = new ActorContextManager;
        $user = User::factory()->create();

        $inner = $manager->runAs(ActorContext::forUser($user, 'ci_cd'), function () use ($manager) {
            return $manager->current()->source;
        });

        $this->assertSame('ci_cd', $inner);
        $this->assertSame('system', $manager->current()->source);
    }

    public function test_run_as_restores_context_on_exception(): void
    {
        $manager = new ActorContextManager;
        $user = User::factory()->create();

        try {
            $manager->runAs(ActorContext::forUser($user, 'ci_cd'), function (): void {
                throw new \RuntimeException('boom');
            });
        } catch (\RuntimeException) {
            // expected
        }

        $this->assertSame('system', $manager->current()->source);
    }

    public function test_from_request_resolves_web_session(): void
    {
        $manager = new ActorContextManager;
        $user = User::factory()->create();
        $user->withAccessToken(new TransientToken);

        $request = Request::create('/x', 'GET', server: ['REMOTE_ADDR' => '10.0.0.9']);
        $request->setUserResolver(fn () => $user);

        $ctx = $manager->fromRequest($request);

        $this->assertSame('web', $ctx->source);
        $this->assertNull($ctx->apiKey);
        $this->assertSame($user->id, $ctx->originator?->id);
    }

    public function test_from_request_resolves_api_key(): void
    {
        $manager = new ActorContextManager;
        $user = User::factory()->create();
        $team = Team::factory()->create(['owner_id' => $user->id]);
        $key = ApiKey::factory()->create([
            'team_id' => $team->id,
            'tokenable_id' => $user->id,
            'tokenable_type' => User::class,
        ]);
        $user->withAccessToken($key);

        $request = Request::create('/x', 'GET');
        $request->setUserResolver(fn () => $user);

        $ctx = $manager->fromRequest($request);

        $this->assertSame('api', $ctx->source);
        $this->assertSame($key->id, $ctx->apiKey?->id);
    }

    public function test_from_request_without_user_is_system(): void
    {
        $manager = new ActorContextManager;
        $request = Request::create('/x', 'GET');

        $this->assertSame('system', $manager->fromRequest($request)->source);
    }
}

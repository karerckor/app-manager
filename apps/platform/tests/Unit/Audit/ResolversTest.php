<?php

namespace Tests\Unit\Audit;

use App\Models\MobileApp;
use App\Models\Team;
use App\Models\User;
use App\Support\Audit\ActorContext;
use App\Support\Audit\ActorContextManager;
use App\Support\Audit\Resolvers\SourceResolver;
use App\Support\Audit\Resolvers\TeamIdResolver;
use App\Support\Audit\Resolvers\UserResolver;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ResolversTest extends TestCase
{
    use RefreshDatabase;

    public function test_user_and_source_resolvers_read_current_context(): void
    {
        $user = User::factory()->create();
        app(ActorContextManager::class)->set(ActorContext::forUser($user, 'api'));

        $this->assertSame($user->id, UserResolver::resolve()?->id);
        $this->assertSame('api', SourceResolver::resolve());
    }

    public function test_team_id_resolver_prefers_auditable_team(): void
    {
        $team = Team::factory()->create();
        $app = MobileApp::factory()->create(['team_id' => $team->id]);

        $this->assertSame($team->id, TeamIdResolver::resolve($app));
    }
}

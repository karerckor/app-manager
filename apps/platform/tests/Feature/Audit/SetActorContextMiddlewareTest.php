<?php

namespace Tests\Feature\Audit;

use App\Models\Team;
use App\Models\User;
use App\Support\Audit\ActorContextManager;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

class SetActorContextMiddlewareTest extends TestCase
{
    use RefreshDatabase;

    public function test_authenticated_web_request_sets_web_context(): void
    {
        Route::middleware(['web', 'auth', \App\Http\Middleware\SetActorContext::class])
            ->get('/_test/actor', fn () => response()->json([
                'source' => app(ActorContextManager::class)->current()->source,
            ]));

        $user = User::factory()->create();
        Team::factory()->create(['owner_id' => $user->id]);

        $this->actingAs($user)->get('/_test/actor')->assertJson(['source' => 'web']);
    }
}

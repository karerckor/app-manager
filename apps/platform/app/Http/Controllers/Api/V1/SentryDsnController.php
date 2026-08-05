<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\SentryDsnRequest;
use App\Http\Resources\Api\V1\SentryDsnResource;
use App\Integrations\Sentry\SentryDsnManager;
use App\Models\SentryDsn;
use App\Models\Team;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;

class SentryDsnController extends Controller
{
    public function __construct(private readonly SentryDsnManager $manager) {}

    public function index(Team $team): AnonymousResourceCollection
    {
        $this->ensureEnabled();

        return SentryDsnResource::collection($team->sentryDsns()->with(['integration', 'routes.mobileApp', 'routes.appIdentifier'])->latest()->get());
    }

    public function show(Team $team, SentryDsn $sentryDsn): SentryDsnResource
    {
        $this->ensureEnabled();
        $this->assertDsn($team, $sentryDsn);

        return new SentryDsnResource($sentryDsn->load(['integration', 'routes.mobileApp', 'routes.appIdentifier']));
    }

    public function store(SentryDsnRequest $request, Team $team): JsonResponse
    {
        $this->ensureEnabled();

        return (new SentryDsnResource($this->manager->create($team, $request->user(), $request->sentryData())))->response()->setStatusCode(201);
    }

    public function update(SentryDsnRequest $request, Team $team, SentryDsn $sentryDsn): SentryDsnResource
    {
        $this->ensureEnabled();
        $this->assertDsn($team, $sentryDsn);

        return new SentryDsnResource($this->manager->update($sentryDsn, $request->user(), $request->sentryData()));
    }

    public function destroy(Request $request, Team $team, SentryDsn $sentryDsn): Response
    {
        $this->ensureEnabled();
        $this->assertDsn($team, $sentryDsn);
        $this->manager->delete($sentryDsn, $request->user());

        return response()->noContent();
    }

    private function assertDsn(Team $team, SentryDsn $dsn): void
    {
        abort_unless($dsn->team_id === $team->id, 404);
    }

    private function ensureEnabled(): void
    {
        abort_unless(config('integrations.enabled') && config('integrations.sentry.enabled'), 404);
    }
}

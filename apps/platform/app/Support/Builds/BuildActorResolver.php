<?php

namespace App\Support\Builds;

use App\Models\BuildRecord;
use App\Support\Audit\ActorContext;
use App\Support\Audit\ActorContextManager;
use Closure;

class BuildActorResolver
{
    public function __construct(private readonly ActorContextManager $contexts) {}

    public function resolve(BuildRecord $build, ?ActorContext $actor = null): ActorContext
    {
        if ($actor !== null) {
            return $actor;
        }

        $current = $this->contexts->current();

        if ($current->actorType() !== 'system') {
            return $current;
        }

        $requester = $build->requestedBy;

        if ($requester === null) {
            return $current;
        }

        $source = data_get($build->context_snapshot, 'source', 'ci_cd');

        return ActorContext::forUser(
            $requester,
            is_string($source) && $source !== '' ? $source : 'ci_cd',
        );
    }

    public function runAs(
        BuildRecord $build,
        Closure $callback,
        ?ActorContext $actor = null,
    ): mixed {
        $resolved = $this->resolve($build, $actor);

        return $this->contexts->runAs(
            $resolved,
            fn () => $callback($resolved),
        );
    }
}

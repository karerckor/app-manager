<?php

namespace App\Integrations\Sentry;

use App\Enums\SentryEventSource;
use App\Enums\SentryRoutingMethod;
use App\Enums\SentryTargetMode;
use App\Models\MobileApp;
use App\Models\SentryDsn;
use App\Models\SentryDsnRoute;
use Illuminate\Http\Request;

class SentryRouteResolver
{
    /** @param array<string, mixed> $event */
    public function resolve(SentryDsn $dsn, Request $request, array $event): SentryDsnRoute
    {
        if ($dsn->target_mode === SentryTargetMode::Selected) {
            return $this->resolveSelected($dsn, $request, $event);
        }

        return $this->resolveDynamicScope($dsn, $request, $event);
    }

    /** @param array<string, mixed> $event */
    private function resolveSelected(SentryDsn $dsn, Request $request, array $event): SentryDsnRoute
    {
        $routes = $dsn->routes()->with(['mobileApp', 'appIdentifier'])->get()
            ->filter(fn (SentryDsnRoute $route) => $route->mobileApp !== null
                && ($route->app_identifier_id === null || $route->appIdentifier !== null))
            ->values();

        if ($routes->isEmpty()) {
            throw new SentryRoutingException('This DSN does not have any application routes.');
        }

        if ($routes->count() === 1) {
            $route = $routes->first();

            if ($route->app_identifier_id === null && $dsn->identifier_key) {
                [, $identifier] = $this->routingValues($dsn, $request, $event);

                if ($identifier !== null) {
                    return $this->routeForApp($dsn, $route->mobileApp, $identifier, $route->public_key);
                }
            }

            return $route;
        }

        [$appSlug, $identifier] = $this->routingValues($dsn, $request, $event);

        if ($appSlug === null) {
            throw new SentryRoutingException("The routing value '{$dsn->app_key}' is missing.");
        }

        $candidates = $routes->filter(fn (SentryDsnRoute $route) => $this->same(
            $route->mobileApp->slug,
            $appSlug,
        ));

        if ($identifier !== null) {
            $candidates = $candidates->filter(function (SentryDsnRoute $route) use ($identifier) {
                $target = $route->appIdentifier;

                return ! $target || ($this->same($target->identifier, $identifier)
                    || $this->same($target->platform->value, $identifier));
            });
        }

        if ($candidates->count() !== 1) {
            throw new SentryRoutingException('The routing values do not identify exactly one configured application target.');
        }

        $route = $candidates->first();

        return $route->app_identifier_id === null && $identifier !== null
            ? $this->routeForApp($dsn, $route->mobileApp, $identifier, $route->public_key)
            : $route;
    }

    /** @param array<string, mixed> $event */
    private function resolveDynamicScope(SentryDsn $dsn, Request $request, array $event): SentryDsnRoute
    {
        [$appSlug, $identifier] = $this->routingValues($dsn, $request, $event);

        if ($appSlug === null) {
            throw new SentryRoutingException("The routing value '{$dsn->app_key}' is missing.");
        }

        $app = MobileApp::query()
            ->where('team_id', $dsn->team_id)
            ->whereRaw('LOWER(slug) = ?', [mb_strtolower(trim($appSlug))])
            ->when(
                $dsn->target_mode === SentryTargetMode::Blueprints,
                fn ($query) => $query->whereIn(
                    'blueprint_id',
                    $dsn->blueprints()->select('blueprints.id'),
                ),
            )
            ->with('appIdentifiers')
            ->first();

        if (! $app) {
            throw new SentryRoutingException('The routing values do not identify an application allowed by this DSN.');
        }

        return $this->routeForApp($dsn, $app, $identifier, $dsn->public_key);
    }

    private function routeForApp(
        SentryDsn $dsn,
        MobileApp $app,
        ?string $identifier,
        string $publicKey,
    ): SentryDsnRoute {
        $app->loadMissing('appIdentifiers');
        $target = null;

        if ($identifier !== null) {
            $matches = $app->appIdentifiers
                ->filter(fn ($candidate) => $this->same($candidate->identifier, $identifier)
                    || $this->same($candidate->platform->value, $identifier))
                ->unique('id')
                ->values();

            if ($matches->count() !== 1) {
                throw new SentryRoutingException('The identifier or platform does not identify exactly one application target.');
            }

            $target = $matches->first();
        }

        $route = new SentryDsnRoute([
            'public_key' => $publicKey,
            'sentry_dsn_id' => $dsn->id,
            'mobile_app_id' => $app->id,
            'app_identifier_id' => $target?->id,
        ]);
        $route->setRelation('sentryDsn', $dsn);
        $route->setRelation('mobileApp', $app);
        $route->setRelation('appIdentifier', $target);

        return $route;
    }

    /**
     * @param  array<string, mixed>  $event
     * @return array{?string, ?string}
     */
    private function routingValues(SentryDsn $dsn, Request $request, array $event): array
    {
        return match ($dsn->routing_method) {
            SentryRoutingMethod::Query => [
                $this->scalar($request->query($dsn->app_key)),
                $this->scalar($dsn->identifier_key ? $request->query($dsn->identifier_key) : null),
            ],
            SentryRoutingMethod::Header => [
                $this->scalar($request->header($dsn->app_key)),
                $this->scalar($dsn->identifier_key ? $request->header($dsn->identifier_key) : null),
            ],
            SentryRoutingMethod::Event => [
                $this->eventValue($event, $dsn->event_source, $dsn->app_key),
                $dsn->identifier_key
                    ? $this->eventValue($event, $dsn->event_source, $dsn->identifier_key)
                    : null,
            ],
        };
    }

    /** @param array<string, mixed> $event */
    private function eventValue(
        array $event,
        ?SentryEventSource $source,
        string $key,
    ): ?string {
        $sources = $source === null || $source === SentryEventSource::Auto
            ? ['contexts', 'extra', 'tags', 'attributes']
            : [$source->value];

        foreach ($sources as $root) {
            $container = $event[$root] ?? [];
            $value = is_array($container) && array_key_exists($key, $container)
                ? $container[$key]
                : data_get($container, $key);
            $value = $this->scalar($value);

            if ($value !== null) {
                return $value;
            }
        }

        return null;
    }

    private function scalar(mixed $value): ?string
    {
        if (! is_scalar($value)) {
            return null;
        }

        $value = trim((string) $value);

        return $value === '' ? null : $value;
    }

    private function same(string $left, string $right): bool
    {
        return mb_strtolower(trim($left)) === mb_strtolower(trim($right));
    }
}

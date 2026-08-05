<?php

namespace App\Integrations\Sentry;

use App\Enums\SentryEventSource;
use App\Enums\SentryRoutingMethod;
use App\Models\SentryDsn;
use App\Models\SentryDsnRoute;
use Illuminate\Http\Request;

class SentryRouteResolver
{
    /** @param array<string, mixed> $event */
    public function resolve(SentryDsn $dsn, Request $request, array $event): SentryDsnRoute
    {
        $routes = $dsn->routes()->with(['mobileApp', 'appIdentifier'])->get();

        if ($routes->isEmpty()) {
            throw new SentryRoutingException('This DSN does not have any application routes.');
        }

        if ($routes->count() === 1) {
            return $routes->first();
        }

        [$appSlug, $identifier] = match ($dsn->routing_method) {
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

        return $candidates->first();
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

<?php

namespace App\Support\Apps;

use App\Models\AppIdentifier;
use App\Models\Audit;
use App\Models\MobileApp;
use App\Models\Team;

class AppActivityFeed
{
    public static function for(Team $team, MobileApp $app, int $limit = 12): array
    {
        $events = [
            ...self::buildEvents($team, $app),
            ...self::auditEvents($team, $app),
        ];

        usort($events, fn ($a, $b) => ($b['timestamp'] <=> $a['timestamp']));

        return array_slice($events, 0, $limit);
    }

    private static function buildEvents(Team $team, MobileApp $app): array
    {
        $events = [];

        $builds = $app->buildRecords()->with(['artifacts', 'requestedBy'])->latest()->limit(30)->get();

        foreach ($builds as $build) {
            $url = route('accounts.apps.builds.show', [$team, $app, $build]);
            $actor = $build->requestedBy ? ['id' => $build->requestedBy->id, 'name' => $build->requestedBy->name] : null;

            $events[] = [
                'type' => 'build_created',
                'title' => 'Build #'.$build->id.' created',
                'actor' => $actor,
                'source' => null,
                'timestamp' => $build->created_at,
                'url' => $url,
            ];
            if ($build->finished_at !== null) {
                $events[] = [
                    'type' => 'build_'.$build->status->value,
                    'title' => 'Build #'.$build->id.' '.str_replace('_', ' ', $build->status->value),
                    'actor' => $actor,
                    'source' => null,
                    'timestamp' => $build->finished_at,
                    'url' => $url,
                ];
            }
            foreach ($build->artifacts as $artifact) {
                $events[] = [
                    'type' => 'artifact_uploaded',
                    'title' => 'Artifact '.$artifact->name.' uploaded',
                    'actor' => $actor,
                    'source' => null,
                    'timestamp' => $artifact->created_at,
                    'url' => $url,
                ];
            }
        }

        return $events;
    }

    private static function auditEvents(Team $team, MobileApp $app): array
    {
        $identifierIds = $app->appIdentifiers()->pluck('id');

        $audits = Audit::query()
            ->where('team_id', $team->id)
            ->where(function ($q) use ($app, $identifierIds) {
                $q->where(fn ($w) => $w->where('auditable_type', MobileApp::class)->where('auditable_id', $app->id))
                    ->orWhere(fn ($w) => $w->where('auditable_type', AppIdentifier::class)->whereIn('auditable_id', $identifierIds));
            })
            ->with('user:id,name')
            ->latest()
            ->limit(30)
            ->get();

        $url = route('accounts.apps.show', ['team' => $team, 'mobileApp' => $app]);

        return $audits->map(function (Audit $audit) use ($url) {
            $model = class_basename($audit->auditable_type);

            return [
                'type' => strtolower($model).'_'.$audit->event,
                'title' => self::auditTitle($model, $audit->event),
                'actor' => $audit->user ? ['id' => $audit->user->id, 'name' => $audit->user->name] : null,
                'source' => $audit->source,
                'timestamp' => $audit->created_at,
                'url' => $url,
            ];
        })->all();
    }

    private static function auditTitle(string $model, string $event): string
    {
        $label = match ($model) {
            'MobileApp' => 'App',
            'AppIdentifier' => 'Identifier',
            default => $model,
        };

        return "{$label} {$event}";
    }
}

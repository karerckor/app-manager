<?php

namespace App\Support\Apps;

use App\Models\MobileApp;
use App\Models\Team;

class AppActivityFeed
{
    public static function for(Team $team, MobileApp $app, int $limit = 12): array
    {
        $events = [];

        $builds = $app->buildRecords()->with('artifacts')->latest()->limit(30)->get();

        foreach ($builds as $build) {
            $url = route('accounts.apps.builds.show', [$team, $app, $build]);
            $events[] = [
                'type' => 'build_created',
                'title' => 'Build #'.$build->id.' created',
                'timestamp' => $build->created_at,
                'url' => $url,
            ];
            if ($build->finished_at !== null) {
                $events[] = [
                    'type' => 'build_'.$build->status->value,
                    'title' => 'Build #'.$build->id.' '.str_replace('_', ' ', $build->status->value),
                    'timestamp' => $build->finished_at,
                    'url' => $url,
                ];
            }
            foreach ($build->artifacts as $artifact) {
                $events[] = [
                    'type' => 'artifact_uploaded',
                    'title' => 'Artifact '.$artifact->name.' uploaded',
                    'timestamp' => $artifact->created_at,
                    'url' => $url,
                ];
            }
        }

        usort($events, fn ($a, $b) => ($b['timestamp'] <=> $a['timestamp']));

        return array_slice($events, 0, $limit);
    }
}

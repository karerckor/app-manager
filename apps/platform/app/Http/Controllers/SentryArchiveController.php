<?php

namespace App\Http\Controllers;

use App\Models\MobileApp;
use App\Models\SentryEnvelope;
use App\Models\Team;
use App\Support\Sentry\SentryArchive;
use Symfony\Component\HttpFoundation\StreamedResponse;

class SentryArchiveController extends Controller
{
    public function __invoke(
        Team $team,
        MobileApp $mobileApp,
        SentryEnvelope $sentryEnvelope,
        SentryArchive $archives,
    ): StreamedResponse {
        abort_unless(
            $sentryEnvelope->team_id === $team->id
            && $sentryEnvelope->mobile_app_id === $mobileApp->id,
            404,
        );

        return $archives->download($sentryEnvelope);
    }
}

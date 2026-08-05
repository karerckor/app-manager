<?php

namespace App\Http\Controllers;

use App\Enums\BuildStatus;
use App\Models\BuildRecord;
use App\Models\MobileApp;
use App\Models\Team;
use App\Support\Builds\BuildLifecycle;
use Illuminate\Http\RedirectResponse;

class BuildLifecycleController extends Controller
{
    public function cancel(
        Team $team,
        MobileApp $mobileApp,
        BuildRecord $buildRecord,
        BuildLifecycle $lifecycle,
    ): RedirectResponse {
        $this->authorize('manageCredentials', $team);

        $lifecycle->finish($buildRecord, BuildStatus::Cancelled);

        return back()->with('status', 'Build cancelled. Runners will stop at the next status check.');
    }
}

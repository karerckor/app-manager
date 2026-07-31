<?php

namespace App\Billing;

use App\Billing\Contracts\RecordsUsage;
use App\Models\BuildRecord;

final class NullUsageRecorder implements RecordsUsage
{
    public function recordBuildRun(BuildRecord $build): void
    {
        //
    }
}

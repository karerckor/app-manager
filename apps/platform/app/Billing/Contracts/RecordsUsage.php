<?php

namespace App\Billing\Contracts;

use App\Models\BuildRecord;

interface RecordsUsage
{
    public function recordBuildRun(BuildRecord $build): void;
}

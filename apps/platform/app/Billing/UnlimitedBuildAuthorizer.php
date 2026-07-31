<?php

namespace App\Billing;

use App\Billing\Contracts\AuthorizesBuildCreation;
use App\Models\Team;

final class UnlimitedBuildAuthorizer implements AuthorizesBuildCreation
{
    public function authorize(Team $team): void
    {
        //
    }
}

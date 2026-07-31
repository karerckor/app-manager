<?php

namespace App\Billing\Contracts;

use App\Models\Team;

interface AuthorizesBuildCreation
{
    public function authorize(Team $team): void;
}

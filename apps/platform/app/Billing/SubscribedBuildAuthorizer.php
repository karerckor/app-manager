<?php

namespace App\Billing;

use App\Billing\Contracts\AuthorizesBuildCreation;
use App\Models\Team;

final class SubscribedBuildAuthorizer implements AuthorizesBuildCreation
{
    public function authorize(Team $team): void
    {
        BillingConfiguration::assertReady();

        abort_unless(
            $team->subscribed(config('billing.subscription', 'default')),
            402,
            'An active subscription is required to create builds.',
        );
    }
}

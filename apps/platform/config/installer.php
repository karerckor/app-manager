<?php

$selfHosted = env('APP_DISTRIBUTION', 'self-hosted') === 'self-hosted';

return [
    /*
    |--------------------------------------------------------------------------
    | Self-hosted web installer
    |--------------------------------------------------------------------------
    |
    | The installer is intentionally unavailable for the hosted distribution.
    | It creates the first owner and stores instance-level integration secrets
    | encrypted with APP_KEY. Disable it for tests or externally provisioned
    | installations.
    |
    */
    'enabled' => $selfHosted && env('SELF_HOSTED_INSTALLER_ENABLED', true),
];

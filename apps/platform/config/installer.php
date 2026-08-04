<?php

$selfHosted = env('APP_DISTRIBUTION', 'self-hosted') === 'self-hosted';
$bootstrap = env('APP_MANAGER_BOOTSTRAP', 'installer');

return [
    /*
    |--------------------------------------------------------------------------
    | First-run bootstrap mode
    |--------------------------------------------------------------------------
    |
    | "installer" hands the first-run experience to the web installer.
    | "admin" creates the first owner from the ADMIN_* variables below.
    | "demo" seeds the disposable demo account. Applied by `app:bootstrap`.
    |
    */
    'bootstrap' => $bootstrap,

    'admin' => [
        'name' => env('ADMIN_NAME', 'Owner'),
        'email' => env('ADMIN_EMAIL'),
        'password' => env('ADMIN_PASSWORD'),
        'team_name' => env('ADMIN_TEAM_NAME', 'My Team'),
    ],

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
    | Any bootstrap mode other than "installer" provisions the owner itself, so
    | the installer screen must not be reachable: one variable, not two.
    |
    */
    'enabled' => $selfHosted && env('SELF_HOSTED_INSTALLER_ENABLED', true) && $bootstrap === 'installer',

    /*
    |--------------------------------------------------------------------------
    | Public registration
    |--------------------------------------------------------------------------
    |
    | Fortify registration is closed by default. A self-hosted instance gets
    | its accounts from the installer, from `app:bootstrap`, or from a team
    | invitation — never from a stranger hitting /register.
    |
    */
    'public_registration' => (bool) env('ALLOW_PUBLIC_REGISTRATION', false),
];

<?php

return [
    /*
    |--------------------------------------------------------------------------
    | Team invitations
    |--------------------------------------------------------------------------
    |
    | When invitations are disabled, workspace managers create user accounts
    | directly and assign their initial passwords from Team Management.
    |
    */
    'invitations_enabled' => env('TEAM_INVITATIONS_ENABLED', true),
];

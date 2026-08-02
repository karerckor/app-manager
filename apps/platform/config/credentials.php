<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Permitted credential storage modes
    |--------------------------------------------------------------------------
    |
    | The instance-wide ceiling. A team may narrow this set but never widen it.
    | "local" stores the secret in App Manager; "external" stores only a
    | reference to the secret in the team's own vault.
    |
    */

    'storage_modes' => env('CREDENTIAL_STORAGE_MODES', 'local,external'),

    /*
    |--------------------------------------------------------------------------
    | Credential API profile
    |--------------------------------------------------------------------------
    |
    | "full" exposes every credential endpoint. "dashboard_only" does not
    | register the endpoints that move secret material, and suppresses
    | credential webhooks and notifications. Switching profiles is a deploy,
    | not a runtime toggle: routes are resolved once, at boot.
    |
    */

    'api_profile' => env('CREDENTIALS_API_PROFILE', 'full'),

];

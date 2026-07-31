<?php

namespace App\Integrations\CiCd\Contracts;

use App\Integrations\CiCd\Data\OAuthTokens;
use App\Models\Integration;

interface OAuthCredentialStore
{
    public function get(Integration $integration): OAuthTokens;

    public function put(Integration $integration, OAuthTokens $tokens): void;

    public function forget(Integration $integration): void;
}

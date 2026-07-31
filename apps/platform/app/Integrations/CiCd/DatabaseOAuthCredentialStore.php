<?php

namespace App\Integrations\CiCd;

use App\Integrations\CiCd\Contracts\OAuthCredentialStore;
use App\Integrations\CiCd\Data\OAuthTokens;
use App\Models\Integration;
use App\Models\IntegrationOAuthCredential;
use DateTimeImmutable;
use RuntimeException;

class DatabaseOAuthCredentialStore implements OAuthCredentialStore
{
    public function get(Integration $integration): OAuthTokens
    {
        $credential = $integration->oauthCredential()->first();

        if (! $credential || $credential->access_token === '') {
            throw new RuntimeException($integration->provider->label().' integration has no OAuth credentials.');
        }

        return new OAuthTokens(
            accessToken: $credential->access_token,
            refreshToken: $credential->refresh_token,
            tokenType: $credential->token_type,
            scopes: $credential->scopes ?? [],
            expiresAt: $credential->expires_at
                ? new DateTimeImmutable($credential->expires_at->toIso8601String())
                : null,
        );
    }

    public function put(Integration $integration, OAuthTokens $tokens): void
    {
        IntegrationOAuthCredential::query()->updateOrCreate(
            ['integration_id' => $integration->id],
            [
                'access_token' => $tokens->accessToken,
                'refresh_token' => $tokens->refreshToken,
                'token_type' => $tokens->tokenType,
                'scopes' => $tokens->scopes,
                'expires_at' => $tokens->expiresAt,
            ],
        );
        $integration->unsetRelation('oauthCredential');
    }

    public function forget(Integration $integration): void
    {
        $integration->oauthCredential()->delete();
        $integration->unsetRelation('oauthCredential');
    }
}

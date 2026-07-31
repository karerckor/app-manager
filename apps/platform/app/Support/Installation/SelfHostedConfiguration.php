<?php

namespace App\Support\Installation;

use App\Models\Installation;
use App\Models\User;
use App\Support\Storage\MediaStorageConfiguration;
use Illuminate\Support\Facades\Schema;
use Throwable;

class SelfHostedConfiguration
{
    public const INSTALLATION_ID = 'self-hosted';

    public function __construct(private readonly MediaStorageConfiguration $mediaStorage) {}

    public function enabled(): bool
    {
        return (bool) config('installer.enabled')
            && config('billing.distribution') === 'self-hosted';
    }

    public function installed(): bool
    {
        if (! $this->enabled()) {
            return true;
        }

        try {
            if (Schema::hasTable('installations')
                && Installation::query()->whereKey(self::INSTALLATION_ID)->exists()) {
                return true;
            }

            // Existing self-hosted deployments predate the installer. A populated
            // users table must never expose a fresh first-user setup screen.
            return Schema::hasTable('users') && User::query()->exists();
        } catch (Throwable) {
            return false;
        }
    }

    public function apply(): void
    {
        if (! $this->enabled()) {
            return;
        }

        try {
            if (! Schema::hasTable('installations')) {
                return;
            }

            $installation = Installation::query()->find(self::INSTALLATION_ID);
        } catch (Throwable) {
            return;
        }

        if (! $installation) {
            return;
        }

        $settings = $installation->settings;

        if (! is_array($settings)) {
            return;
        }

        $github = data_get($settings, 'integrations.github', []);
        $gitlab = data_get($settings, 'integrations.gitlab', []);
        $webhooks = data_get($settings, 'integrations.webhooks', []);
        $storage = data_get($settings, 'storage');

        if (is_array($storage)) {
            $this->mediaStorage->apply($storage);
        }

        config([
            'app.name' => $installation->name,
            'integrations.enabled' => (bool) data_get($settings, 'integrations.enabled', false),
            'integrations.github.enabled' => (bool) data_get($github, 'enabled', false),
            'integrations.github.app_id' => data_get($github, 'app_id'),
            'integrations.github.client_id' => data_get($github, 'client_id'),
            'integrations.github.client_secret' => data_get($github, 'client_secret'),
            'integrations.github.slug' => data_get($github, 'slug'),
            'integrations.github.private_key' => data_get($github, 'private_key'),
            'integrations.github.webhook_secret' => data_get($github, 'webhook_secret'),
            'integrations.github.api_url' => data_get($github, 'api_url', 'https://api.github.com'),
            'integrations.github.web_url' => data_get($github, 'web_url', 'https://github.com'),
            'integrations.gitlab.enabled' => (bool) data_get($gitlab, 'enabled', false),
            'integrations.gitlab.client_id' => data_get($gitlab, 'client_id'),
            'integrations.gitlab.client_secret' => data_get($gitlab, 'client_secret'),
            'integrations.gitlab.api_url' => data_get($gitlab, 'api_url', 'https://gitlab.com/api/v4'),
            'integrations.gitlab.web_url' => data_get($gitlab, 'web_url', 'https://gitlab.com'),
            'integrations.gitlab.scopes' => data_get($gitlab, 'scopes', ['api', 'openid']),
            'integrations.webhooks.enabled' => (bool) data_get($webhooks, 'enabled', false),
            'webhooks.allow_private_addresses' => (bool) data_get($webhooks, 'allow_private_addresses', false),
        ]);
    }
}

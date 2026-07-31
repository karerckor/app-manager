<?php

namespace App\Billing;

use LogicException;

final class BillingConfiguration
{
    public static function enabled(): bool
    {
        return (bool) config('billing.enabled', false);
    }

    public static function assertReady(): void
    {
        self::assertEnvironment();

        if (! self::enabled()) {
            return;
        }

        foreach ([
            'cashier.key',
            'cashier.secret',
            'cashier.webhook.secret',
            'billing.prices.base_monthly',
            'billing.prices.build_runs',
            'billing.meters.build_runs.id',
            'billing.meters.build_runs.event_name',
        ] as $key) {
            if (! is_string(config($key)) || config($key) === '') {
                throw new LogicException("Missing required billing configuration: {$key}.");
            }
        }
    }

    public static function assertEnvironment(): void
    {
        $distribution = config('billing.distribution');
        $mode = config('billing.mode');

        if (! in_array($distribution, ['self-hosted', 'hosted'], true)) {
            throw new LogicException('APP_DISTRIBUTION must be either "self-hosted" or "hosted".');
        }

        if (! in_array($mode, ['off', 'test', 'live'], true)) {
            throw new LogicException('BILLING_MODE must be "off", "test", or "live".');
        }

        if ($distribution === 'self-hosted' && $mode !== 'off') {
            throw new LogicException('The self-hosted distribution requires billing mode "off".');
        }

        if ($distribution === 'hosted' && app()->isProduction() && $mode !== 'live') {
            throw new LogicException('Hosted production requires live billing mode.');
        }

        if ($mode === 'live' && ($distribution !== 'hosted' || ! app()->isProduction())) {
            throw new LogicException('Live billing mode may only be used by hosted production.');
        }
    }
}

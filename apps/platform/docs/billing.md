# Hosted billing

App Manager uses Laravel Cashier with Stripe for the hosted distribution. The
self-hosted distribution always uses unlimited build authorization and a null
usage recorder, so it creates no billing usage events and makes no Stripe API
calls.

## Runtime modes

| Distribution | Billing mode | Result |
| --- | --- | --- |
| `self-hosted` | `off` | Billing UI, webhooks, subscription checks, and usage reporting are disabled. |
| `hosted` | `test` | Stripe test-mode Checkout, Portal, webhooks, and metered usage are enabled outside production. |
| `hosted` | `live` | Live Stripe billing; accepted only when `APP_ENV=production`. |

Hosted production rejects any mode other than `live`. A non-production
environment rejects `live` mode to reduce the chance of charging real
customers during development.

## Stripe catalog

Create one product with two monthly recurring prices:

1. A fixed `base monthly` Price.
2. A usage-based `build runs` Price attached to a meter.

Configure the meter as follows:

- Event name: `app_manager_build_runs`
- Event ingestion: `Raw`
- Aggregation: `Sum`
- Customer payload key: `stripe_customer_id`
- Value payload key: `value`

Configure the build-runs Price with graduated tiers. The first tier can have a
zero unit amount for the number of builds included in the base subscription;
subsequent tiers contain the overage rates. Do not add a flat amount to the
metered Price because the base Price already supplies the monthly fee.

Copy the resulting identifiers into:

```dotenv
APP_DISTRIBUTION=hosted
BILLING_MODE=test

STRIPE_KEY=pk_test_...
STRIPE_SECRET=sk_test_...
STRIPE_WEBHOOK_SECRET=whsec_...
STRIPE_PRICE_BASE_MONTHLY=price_...
STRIPE_PRICE_BUILD_RUNS=price_...
STRIPE_METER_BUILD_RUNS_ID=mtr_...
STRIPE_METER_BUILD_RUNS_EVENT=app_manager_build_runs
```

For local webhook testing:

```shell
stripe listen --forward-to http://localhost:8000/stripe/webhook
```

Use the webhook signing secret printed by `stripe listen`.

For the hosted deployment, Cashier can create the production endpoint with its
required event list:

```shell
php artisan cashier:webhook --url=https://app.example.com/stripe/webhook
```

Store the signing secret from the created Stripe endpoint in
`STRIPE_WEBHOOK_SECRET`.

## Usage delivery

An accepted manual or CI/CD build creates a `billing_usage_events` outbox row
inside the same database transaction as the build. A queued job submits the
event through Cashier with `build_run:{build UUID}` as the Stripe identifier.
The database unique constraint prevents normal duplicate delivery, while the
same Stripe identifier protects retries within Stripe's identifier retention
window.

Run a worker that includes the billing queue:

```shell
php artisan queue:work --queue=integrations,webhooks,billing,default
```

The scheduler re-enqueues unreported events, so production must run both the
queue worker and `php artisan schedule:work`.

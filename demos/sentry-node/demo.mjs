import * as Sentry from '@sentry/node';

const dsn = process.env.APP_MANAGER_SENTRY_DSN;
const appIdentifier = process.env.APP_IDENTIFIER || 'web';

if (!dsn) {
  throw new Error('APP_MANAGER_SENTRY_DSN is missing. Copy .env.example to .env and set the routed DSN.');
}

Sentry.init({
  dsn,
  environment: 'local-demo',
  release: 'example-app@1.0.0',
  tracesSampleRate: 1.0,
});

// A base routed DSN uses these values to resolve one target inside example-app.
// A direct target DSN also accepts them, but its public key already fixes the route.
Sentry.setContext('app', {
  slug: 'example-app',
  identifier: appIdentifier,
  runtime: `node ${process.version}`,
});
Sentry.setTags({
  'app.slug': 'example-app',
  'app.identifier': appIdentifier,
  demo: 'sentry-node',
});
Sentry.setExtra('demo_started_at', new Date().toISOString());

console.log('Sending a checkout trace and an exception with User + Request data…');

await Sentry.startSpan(
  {
    name: 'POST /demo/checkout',
    op: 'http.server',
    attributes: {
      'app.slug': 'example-app',
      'app.identifier': appIdentifier,
      'demo.order_id': 'order-demo-42',
    },
  },
  async () => {
    await Sentry.startSpan(
      {
        name: 'reserve demo inventory',
        op: 'db.query',
      },
      async () => new Promise((resolve) => setTimeout(resolve, 80)),
    );

    try {
      throw new Error('Demo checkout failed: inventory reservation expired');
    } catch (error) {
      Sentry.withScope((scope) => {
        scope.setUser({
          id: 'user-demo-42',
          email: 'buyer@example.test',
          username: 'demo-buyer',
          ip_address: '203.0.113.42',
          plan: 'demo-pro',
        });
        scope.setTags({
          feature: 'checkout',
          handled: 'true',
        });
        scope.setExtras({
          order_id: 'order-demo-42',
          cart_total: 149.99,
        });

        // Request is a top-level Sentry event field. An event processor lets a
        // non-HTTP demo attach the same shape that framework integrations emit.
        scope.addEventProcessor((event) => ({
          ...event,
          request: {
            url: 'https://checkout.example.test/api/orders/order-demo-42',
            method: 'POST',
            query_string: 'source=telemetry-demo&coupon=SAVE20',
            headers: {
              'content-type': 'application/json',
              'user-agent': 'app-manager-sentry-demo/1.0',
              'x-request-id': 'request-demo-42',
            },
            data: {
              order_id: 'order-demo-42',
              items: [{ sku: 'demo-widget', quantity: 2 }],
              total: 149.99,
            },
            env: {
              REMOTE_ADDR: '203.0.113.42',
            },
          },
        }));

        Sentry.captureException(error);
      });
    }
  },
);

Sentry.captureMessage('Sentry Node demo completed', 'info');

const flushed = await Sentry.flush(10_000);

if (!flushed) {
  throw new Error('Sentry SDK did not flush all events before the timeout.');
}

console.log(`Done. Open Example App → Errors & traces (${appIdentifier}).`);

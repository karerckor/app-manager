# Sentry Node demo

This demo sends a handled checkout error, a message, and a transaction with a
child span to the routed DSN configured for the local `example-app`. The error
also contains demo `User` data and a full top-level `Request` payload, including
headers, query string, body, and request environment.

```bash
npm install
npm run demo
```

The local App Manager platform must be available at the host encoded in `.env`.
When using the base routed DSN, `APP_IDENTIFIER` can be an exact package/domain
or a platform value such as `ios`, `android`, or `web`. After the script
finishes, open **Example App → Errors & traces** and filter by app target.
Select the newest checkout error to inspect the `User` and `Request` tabs.

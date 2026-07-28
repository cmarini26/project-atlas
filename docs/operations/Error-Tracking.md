# Production Error Tracking

**Service:** Sentry  
**Atlas driver:** `App\ErrorTracking\SentryErrorTracker`  
**Verification:** `php artisan atlas:verify-error-tracking --send`

## Scope

Sentry receives reported Laravel exceptions from HTTP requests, queue workers,
scheduled commands, and other console processes. Laravel's local logging remains
enabled; Sentry is additive and does not replace the application logs.

The first private-beta integration is intentionally backend-only. Browser/Vue
monitoring, session replay, Sentry Logs, profiling, and AI instrumentation are
not enabled.

## Privacy defaults

- `send_default_pii=false`
- request bodies are never attached
- cookies, headers, environment data, query strings, and user identity are
  removed by `before_send`
- SQL bindings are disabled
- HTTP-client, notification, cache, command, and log breadcrumbs are disabled
- custom Atlas context uses a small scalar-only allowlist and never passes
  arbitrary context through to Sentry

Do not loosen these defaults without a separate customer-data review.

## Production configuration

Store these values only in the production environment:

```dotenv
ERROR_TRACKING_DRIVER=sentry
ERROR_TRACKING_DSN=<project DSN from Sentry>
SENTRY_TRACES_SAMPLE_RATE=0.05
SENTRY_RELEASE=<deployed commit or release identifier>
```

After changing them:

```bash
php artisan config:cache
supervisorctl restart 'atlas-worker-*:*'
systemctl reload php8.3-fpm
```

## Acceptance test

Run:

```bash
sudo -u www-data php artisan atlas:verify-error-tracking --send
```

Then confirm in Sentry:

1. exactly one issue/event named `Atlas controlled error-tracking verification`
   appears in the `production` environment;
2. its Atlas context contains only `component=error_tracking` and
   `verification=true`;
3. it contains no cookies, authorization headers, request body, query string,
   customer content, email address, or IP address;
4. an email notification is received by the named incident owner.

Record the Sentry event link and notification timestamp in SCRUM-13. Resolve the
verification issue after acceptance; do not ignore the project globally.

## Alert baseline

During private beta, notify the named owner for:

- any new production issue;
- a previously resolved issue regressing;
- an issue escalating materially in frequency.

Tune noisy framework or expected validation exceptions only after inspecting
real events. Do not broadly discard exception classes to make the dashboard
look clean.

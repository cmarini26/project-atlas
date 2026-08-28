# Stripe Billing Runbook

Operating Atlas billing (epic CM-73–79). Covers setup, secrets, webhook
configuration, the access gate, and operator reconciliation.

## Shape

```
BillingProvider (interface)              app/Billing/Contracts
├── StripeBillingProvider                the only class that touches stripe-php
└── FakeBillingProvider                  in-memory, local + PHPUnit only

BillingProfileService     the single writer of billing truth (billing_profiles)
BillingCheckoutService    start subscription checkout / open the billing portal
StripeWebhookProcessor    apply verified webhook events to billing state
BillingAccess             "may this company use paid features?" — one decision point
```

Product code never imports `Stripe\*`. Vendor errors surface as
`App\Billing\Exceptions\BillingException` (and `WebhookVerificationException`).

## Environment

| Var | Meaning |
|-----|---------|
| `BILLING_DRIVER` | `stripe` in staging/prod. `fake` (no network) for local + PHPUnit only. |
| `STRIPE_SECRET` | Secret key `sk_test_…` / `sk_live_…`. The `stripe` driver refuses to resolve without it. |
| `STRIPE_WEBHOOK_SECRET` | Endpoint signing secret `whsec_…`. Webhooks are rejected (400) without it. |
| `STRIPE_PRICE_ID` | The single Atlas subscription Price `price_…`. Checkout is unavailable until set. |
| `BILLING_GATE_ENABLED` | `false` (default, beta-safe): billing state restricts nothing. `true`: the access gate is live. |

## Stripe dashboard setup

1. **Product + Price.** Create a Product ("Atlas") with one **recurring**
   Price. Copy its `price_…` id → `STRIPE_PRICE_ID`.
2. **Billing portal.** Settings → Billing → Customer portal: activate it and
   allow "cancel subscription" / "update payment method". The
   *Manage billing* button opens this.
3. **Webhook endpoint.** Developers → Webhooks → Add endpoint:
   - URL: `https://<host>/api/stripe/webhook`
   - Events:
     - `checkout.session.completed`
     - `customer.subscription.created`
     - `customer.subscription.updated`
     - `customer.subscription.deleted`
   - Copy the signing secret → `STRIPE_WEBHOOK_SECRET`.

## Local development

```bash
# forward live test-mode events to the local app
stripe listen --forward-to localhost:8000/api/stripe/webhook
# the CLI prints a whsec_… — put it in .env as STRIPE_WEBHOOK_SECRET

# drive a flow
stripe trigger checkout.session.completed
stripe trigger customer.subscription.updated
```

With `BILLING_DRIVER=fake` (the default local value) none of this is needed —
the fake provider returns canned checkout/portal URLs and decodes webhook
payloads without a signature.

## The flow

1. Owner/admin clicks **Subscribe** → `POST /app/settings/billing/checkout`
   → `BillingCheckoutService` ensures a Stripe customer (tagged
   `metadata.atlas_company_id`), persists the linkage, opens a subscription
   Checkout Session with `client_reference_id = <company id>`, and the browser
   is redirected to Stripe.
2. Stripe redirects back to `/app/settings/billing?checkout=success|cancelled`.
3. **The webhook is the source of truth**, not the redirect.
   `checkout.session.completed` links the customer and mirrors the new
   subscription; `customer.subscription.*` keep `billing_profiles` in sync.
4. `billing_profiles.subscription_status` + `beta_access_override` drive
   `BillingProfile::grantsAccess()`.

## Access gate (CM-78)

- `BILLING_GATE_ENABLED=false` (default): `RequireBillingAccess` is a
  pass-through everywhere. **Keep it off during the private beta.**
- When on, the `billing` route-middleware alias blocks the gated actions
  (currently: approving a recommendation). Web requests redirect to
  Settings → Billing with an error; JSON requests get `402`.
- A company is allowed if its subscription status is `trialing` / `active` /
  `past_due`, **or** `beta_access_override` is set.

Grant/clear the per-company override:

```bash
php artisan billing:beta-access <company-id-or-slug>
php artisan billing:beta-access <company-id-or-slug> --revoke
```

## Reconciliation & support

**Did a webhook arrive / get processed?** `stripe_webhook_events` table —
`stripe_event_id`, `type`, `received_at`, `processed_at` (null = not yet /
failed), `error`. Duplicates are recorded once and skipped.

**Replay an event.** Stripe dashboard → Webhooks → the endpoint → the event →
*Resend*. Processing is idempotent, so a resend is safe.

**A customer is stuck / mismatched.**
1. Check `billing_profiles` for the company: `stripe_customer_id`,
   `stripe_subscription_id`, `subscription_status`.
2. Confirm the subscription's real state in the Stripe dashboard.
3. Resend the latest `customer.subscription.updated` for that subscription to
   re-sync, or set `beta_access_override` to unblock them immediately while you
   investigate.

**`past_due`.** The customer's card failed. They fix it in the billing portal
(*Manage billing*). Access is retained during `past_due`; a subsequent
`customer.subscription.updated` to `active` or `canceled` resolves it.

## Data model

| Table | Purpose |
|-------|---------|
| `billing_profiles` | one per company — Stripe linkage, last-known subscription status/period, `beta_access_override`. Cache of Stripe truth. |
| `stripe_webhook_events` | idempotency key + received/processed log per Stripe event. |

## Tests

- `tests/Unit/Billing/*` — value objects, the fake provider, webhook signature
  verification (real HMAC), and HTTP-mocked `StripeBillingProvider` request
  mapping (`StripeBillingProviderApiTest`, via a fake `Stripe\HttpClient`).
- `tests/Feature/Billing/*` — driver binding, `BillingProfileService`,
  checkout controller + service, webhook controller + processor, settings
  controller, the access gate + middleware, and the `billing:beta-access`
  command.

No test touches the network; `BILLING_DRIVER=fake` in `phpunit.xml`.

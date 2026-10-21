# Payment providers

Supported: **Paystack** and **Flutterwave**. The application only talks to the `PaymentProvider` interface
(`initialize`, `verify`, `verifyWebhookSignature`, `parseWebhook`, `refund`); `PaymentManager` resolves the implementation and the
credentials for the current store.

## Credentials: per store

Every store connects **its own** provider account, so customers pay the merchant directly.

Merchant dashboard → *Store → Settings & payments* (store **owner** only) → *Connect*. Secrets are encrypted at rest, can never be read back
(only `••••` + the last 4 characters is shown), and are never logged.

| | Paystack | Flutterwave |
|---|---|---|
| Secret key | `sk_test_…` / `sk_live_…` | `FLWSECK_TEST-…` / `FLWSECK-…` |
| Webhook secret | the **secret key itself** signs webhooks, nothing extra | the **"secret hash"** you invent (≥ 8 chars), entered both here and in Flutterwave |
| Webhook header | `x-paystack-signature` (HMAC-SHA512 of the body) | `verif-hash` (must equal the secret hash) |
| Events handled | `charge.success` | `charge.completed` |

Optional platform-managed account: set `PAYMENTS_USE_PLATFORM_CREDENTIALS=true` plus `PAYSTACK_SECRET_KEY` /
`FLUTTERWAVE_SECRET_KEY` / `FLUTTERWAVE_WEBHOOK_HASH`. These are used **only** for stores that have no gateway of their own; a store's
own (even disabled) gateway is never overridden.

### Set up Paystack

1. Paystack dashboard → *Settings → API Keys & Webhooks*: copy the **secret key** (use test keys first).
2. Merchant dashboard → connect Paystack with that key.
3. Copy the **Webhook URL** shown in the merchant dashboard (`https://api.mydomain.com/api/v1/webhooks/paystack/<tenant-uuid>`) into Paystack's *Webhook URL* field (test and live separately).

### Set up Flutterwave

1. Flutterwave dashboard → *Settings → API*: copy the **secret key**.
2. *Settings → Webhooks*: set the URL (`…/webhooks/flutterwave/<tenant-uuid>`) and choose a **secret hash**.
3. Merchant dashboard → connect Flutterwave with the secret key **and the same secret hash**.

The tenant UUID in the URL is how the platform knows whose credentials to verify the signature with. A webhook signed with a *different*
store's secret is rejected.

## The payment flow

1. **Checkout** creates the order (`PENDING` / `PENDING`). Totals are computed server-side.
2. `POST /storefront/payments/initialize` (requires `Idempotency-Key`) creates a payment attempt and returns the provider's checkout URL.
   Amount, currency, payer email and the **callback URL are all taken from the database**, never from the request. A fully-discounted order (total 0) is settled without a provider.
3. The customer pays on the provider's page and is redirected to `https://<store>/checkout/payment?reference=…`.
4. **Confirmation** happens in two redundant ways, both ending in the same idempotent code:
   - the storefront calls `GET /storefront/payments/{reference}/verify` (the page polls briefly because the webhook can lag), and
   - the provider's webhook is verified, de-duplicated, and processed by a **queue job**.
5. Confirmation **asks the provider's API** for the transaction's real state and checks the reference, **amount and currency** against our records.
   Only then is the payment `PAID`, the order `payment_status = PAID`, and a `PENDING` order moves to `PROCESSING` (the customer is emailed).

The redirect and the webhook body are *never* trusted as proof of payment, and an order is never marked paid from a frontend response.

> **The queue worker must be running** or webhooks are received but not processed (payments then confirm only when the customer returns to the store). In production run the `queue` container.

### Statuses

| Payment/Order `payment_status` | Meaning |
|---|---|
| `PENDING` | Not paid yet |
| `PAID` | Confirmed by the provider |
| `FAILED` | Declined, or a verification mismatch (see `failureReason`: `amount_mismatch`, `reference_mismatch`, …); another attempt is allowed |
| `PARTIALLY_REFUNDED` / `REFUNDED` | After refunds |

Unpaid `PENDING` orders are cancelled after `UNPAID_ORDER_EXPIRY_HOURS` (default 24; `0` disables) so reserved stock is released; a payment that
arrives for an already-cancelled order is recorded as paid and logged for a manual refund.

## Refunds

`POST /orders/{order}/refund` (owners and admins; requires `Idempotency-Key`; dashboard: order → *Refund*). Amount defaults to the full remainder; partial refunds
are tracked per payment and cannot exceed what is left. The amount is **reserved before** the provider call (concurrent requests cannot over-refund) and released if
the provider rejects. Paystack refunds are by transaction reference; Flutterwave by transaction id (so the payment must have been confirmed first).

## Testing

- **Sandbox**: use each provider's test keys and the test cards/numbers published in their documentation, with the webhook URL pointing at a reachable host. Locally, expose the API with a tunnel (for example `cloudflared tunnel --url http://localhost:8000`).
- **Simulate a Paystack webhook** (proves signature handling; the confirmation step still calls Paystack's API, so a real/test key and a real reference are needed to complete it):

  ```bash
  BODY='{"event":"charge.success","data":{"id":1,"reference":"pay_xxxxxxxx"}}'
  SIG=$(printf '%s' "$BODY" | openssl dgst -sha512 -hmac "$PAYSTACK_SECRET_KEY" | sed 's/^.* //')
  curl -i -X POST "http://localhost:8000/api/v1/webhooks/paystack/<tenant-uuid>" \
    -H "Content-Type: application/json" -H "x-paystack-signature: $SIG" -d "$BODY"
  ```
- **Automated**: `php artisan test --filter=Payment` and `--filter=Webhook`. Provider HTTP is faked (`Http::fake`), and one test swaps in an anonymous provider to prove nothing outside the provider classes depends on Paystack/Flutterwave. No test touches a real provider.

> Run one real **sandbox payment per provider** before going live; this repository's tests cannot prove the live API contract.

## Troubleshooting

| Symptom | Likely cause |
|---|---|
| "Online payment is not available for this store" (422) | The store has no **active** gateway (and platform credentials are off) |
| `PAYMENT_PROVIDER_ERROR` (502) on initialize | Invalid key, wrong environment (test vs live key), or provider outage; the attempt is marked failed and can be retried |
| Webhook returns `401 INVALID_WEBHOOK_SIGNATURE` | Wrong secret/secret hash, webhook URL contains a different store's UUID, the store is suspended, or the body was altered by a proxy |
| Customer paid but the order stays `PENDING` | Queue worker not running, webhook not delivered (blocked by a WAF), or `amount_mismatch` (check logs for `Payment verification mismatch`) |
| Duplicate emails/orders | Should not happen: confirmation and webhooks are idempotent. Check that clients send a stable `Idempotency-Key` per attempt |

## Adding another provider

1. Implement `App\Services\Payments\PaymentProvider`.
2. Add a case to `App\Enums\PaymentProviderType`, a branch in `PaymentManager::make()`, its config in `config/payments.php`, and its key-format rule in `UpdateGatewayRequest`.
3. Convert units **inside** the provider class (the application speaks minor units only).
4. Test with `Http::fake()` like `tests/Feature/Payments/PaymentProviderTest.php`.

## Known limitations

Amounts assume currencies with 2 decimals; tax is not calculated; a refund the provider accepts is recorded as completed (later provider-side
failure is not tracked); only the successful-charge webhook event of each provider is acted on (failures/abandonment are picked up when the customer returns and the verify call runs).

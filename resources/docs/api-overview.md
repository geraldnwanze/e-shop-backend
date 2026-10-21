Multi-tenant e-commerce platform API. One shared API serves every store; this page explains the conventions that apply to **every** endpoint.

## Response envelope

Every response, success or error, uses the same JSON structure:

```json
{
  "status": true,
  "statusCode": 200,
  "message": "Success",
  "data": {},
  "meta": {},
  "requestId": "0f0c0a8e-…"
}
```

- `data` holds the resource(s). Lists are arrays; paginated lists add `currentPage`, `lastPage`, `perPage`, `total` to `meta`.
- Errors set `status: false`, an HTTP status, a human `message`, and `meta.errorCode` (a stable machine-readable code). Validation errors add `meta.errors` as `{ "field": ["message"] }`.
- `requestId` is also returned in the `X-Request-Id` response header. Send your own `X-Request-Id` (8–64 chars) to correlate logs; quote it to support.

### Error codes

| `meta.errorCode` | HTTP | Meaning |
|---|---|---|
| `VALIDATION_FAILED` | 422 | Input invalid; see `meta.errors` |
| `UNAUTHENTICATED` / `INVALID_CREDENTIALS` | 401 | Missing/invalid token, or wrong email/password |
| `FORBIDDEN` / `ACCOUNT_SUSPENDED` / `REGISTRATION_CLOSED` | 403 | Not allowed for this actor |
| `NOT_FOUND` / `TENANT_NOT_FOUND` | 404 | Resource (or store hostname) does not exist **for this tenant** |
| `TENANT_SUSPENDED` | 403 | The store is suspended |
| `CONFLICT` / `INSUFFICIENT_STOCK` / `INVALID_STATE_TRANSITION` / `PLAN_LIMIT_REACHED` / `REQUEST_IN_PROGRESS` | 409 | State conflict |
| `EMPTY_CART` / `IDEMPOTENCY_KEY_REUSED` | 422 | Business-rule validation |
| `TOO_MANY_REQUESTS` | 429 | Rate limited (see `Retry-After`) |
| `PAYMENT_PROVIDER_ERROR` | 502 | The payment/media provider failed |

## Authentication

Laravel Sanctum **bearer tokens**: `Authorization: Bearer <token>`. Tokens are issued by `POST /auth/login` / `POST /auth/register` (merchants and platform admins) and `POST /storefront/auth/login` / `register` (shoppers). Merchant, customer and admin tokens are **not interchangeable**: each token type only works on its own routes.

## Tenant resolution (read this)

The platform is multi-tenant with a shared database; every tenant-owned record is isolated by `tenant_id`, enforced server-side.

| Route group | How the tenant is determined |
|---|---|
| `/storefront/*` (public shop) | From the **hostname** of the store being visited: `icecream.mydomain.com` or a verified custom domain such as `www.icecream.com`. Because the Next.js storefront calls this API from its server, it forwards the visitor's hostname in the **`X-Storefront-Host`** header. Unknown host → `404 TENANT_NOT_FOUND`; suspended → `403 TENANT_SUSPENDED`. |
| Merchant routes (`/products`, `/orders`, `/store`, …) | From the **authenticated user**. Any `tenant_id`, `X-Storefront-Host` or `X-Acting-Tenant` sent by a merchant is ignored. |
| `/admin/*` | Platform administrators only. Explicitly cross-tenant. |
| `/webhooks/{provider}/{tenant}` | The tenant UUID in the URL, authenticated by the provider's signature (below). |

Identifiers in URLs and payloads are **UUIDs**; database ids are never exposed. A UUID that belongs to another tenant behaves exactly like one that does not exist (`404`).

## Conventions

- **Money** is an integer in **minor units** (`150000` = 1,500.00 NGN). Never floats.
- **Idempotency**: `POST /storefront/checkout`, `POST /storefront/payments/initialize` and `POST /orders/{order}/refund` **require** an `Idempotency-Key` header (8–128 chars of `A-Za-z0-9_-:.`). Retrying with the same key and body replays the original response (`Idempotent-Replayed: true`); the same key with a different body returns `422 IDEMPOTENCY_KEY_REUSED`. Failed attempts release the key.
- **Rate limits**: 120 requests/min per user or IP by default; authentication endpoints are stricter (10/min per email+IP). `429` responses include `Retry-After`.
- **Payments are never trusted from the client.** An order is marked paid only after the provider confirms it server-to-server (webhook or verify call), with amount and currency cross-checked.

## Webhooks

`POST /webhooks/{provider}/{tenant}` with `provider` = `paystack` or `flutterwave`. Configure this URL in the provider dashboard (the merchant dashboard shows the exact URL). Requests must carry the provider's signature (`x-paystack-signature` HMAC-SHA512, or Flutterwave's `verif-hash`), verified against that store's own credentials. Responses are always `200` for accepted events (including duplicates); invalid signatures get `401`.

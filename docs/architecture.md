# Architecture

## The multi-tenant model

```
Platform
 ├── Platform administrators        (users with no tenant; role platform_admin)
 └── Tenant                         (a merchant account; status active | suspended)
      ├── Store                     (1 per tenant in the MVP; schema allows more later)
      ├── Domains                   (platform subdomain + verified custom domains)
      ├── Users                     (merchant_owner / merchant_admin / merchant_staff)
      ├── Products, Variants, Categories, Media, Discounts
      ├── Customers, Addresses, Carts, Orders, Payments, Refunds
      ├── Settings, Theme configuration, Payment gateways
      └── Subscription              (which Plan the tenant is on)
Global (not tenant-owned): Themes, Plans, Platform settings
```

A **shared database, shared schema** design: every tenant-owned table has a `tenant_id` foreign key, with composite
unique indexes such as `(tenant_id, slug)`, `(tenant_id, sku)`, `(tenant_id, email)`, `(tenant_id, order_number)`.
Domains are globally unique. Public identifiers are UUIDs; auto-increment ids never leave the API.

## How a request finds its tenant

One place interprets hostnames: `App\Support\Tenancy\TenantResolver`, behind `ResolveTenantMiddleware`.

```
Visitor ─► icecream.mydomain.com/products/vanilla
   Next.js proxy.ts  (normalises the host, rewrites to /s/<host>/…)
   Next server ─► GET {API}/storefront/products/vanilla     X-Storefront-Host: icecream.mydomain.com
   ResolveTenantMiddleware
      normalise host (lowercase, strip protocol/port/trailing dot)
      cache lookup  tenancy:host:<host>  →  tenant id          (negative results cached briefly)
      miss: domains table (verified only), then www↔apex twin, then {slug}.PLATFORM_DOMAIN fallback
      unknown → 404 TENANT_NOT_FOUND · suspended → 403 TENANT_SUSPENDED
      TenantContext::set($tenant)       request-scoped, cleared on terminate
   Controllers/services run with TenantScope active
```

Rules of resolution:

- Only **verified** custom domains resolve. A pending domain never serves a store.
- `www.x.com` and `x.com` are treated as the same site; deeper subdomains are not.
- Reserved labels (`app`, `api`, `www`, `shops`, `admin`, …) and nested labels (`evil.icecream.mydomain.com`) never resolve as stores.
- The cache holds only a tenant **id**, keyed by the normalised hostname, so a hit can never cross hosts. Domain changes purge both the host and its www/apex twin.

Authenticated routes do **not** use the hostname: merchant tenants come from the signed-in user (`SetTenantFromUserMiddleware`),
and any `tenant_id` / `X-Storefront-Host` / `X-Acting-Tenant` a merchant sends is ignored. Storefront customers authenticate
*inside* the host-resolved tenant, so a token issued by store A cannot even be loaded under store B.

Middleware ordering matters and is set in `bootstrap/app.php`: hostname resolution runs **before** authentication (customer
tokens are tenant-scoped), and tenant-from-user runs **before** route-model binding (so bindings are tenant-scoped).

## Isolation mechanics

- `BelongsToTenant` adds `TenantScope` (a global scope). With **no** tenant in context the scope matches nothing (fail closed).
- Creating a tenant-owned record without a tenant throws; creating one for a tenant other than the current one throws; `tenant_id` is immutable.
- `tenant_id` is never mass-assignable.
- Cross-tenant access is an explicit mode: `TenantContext::withoutTenantScope()` for code, and the `/admin` route group (which enables the bypass after checking the `platform_admin` role and the `manage-platform` gate). The bypass is cleared at the end of every request.
- Policies add a second check (permission **and** `user.tenant_id === record.tenant_id`) on top of the scope.
- A UUID belonging to another tenant answers `404`, identical to a missing record.

## Queues, events and the tenant

Queue workers have no request. `TenantContext` is registered as a *scoped* singleton so nothing leaks between jobs, and
**jobs/listeners carry ids, not models** (`OrderPlaced(tenantId, orderId)`), then re-enter the tenant with `runAs()`.
Notifications are built from plain data captured inside the tenant. `ProcessPaymentWebhookJob` finds the payment through
the tenant-scoped query, so a payment reference belonging to another tenant simply does not exist there.

## Money, orders and inventory

- Money is an **integer in minor units** end to end. Conversion for a provider that uses major units (Flutterwave) happens only inside that provider class.
- `CheckoutService` turns a cart into an order in one database transaction: prices, discount, shipping and totals are recomputed from the database; stock is taken with an atomic `UPDATE … WHERE stock >= n`; the order number comes from a per-tenant, per-year sequence inside the same transaction (no gaps from rollbacks); the discount's use is claimed atomically. Any failure rolls everything back.
- Order numbers look like `ORD-2026-000001`, unique per tenant.
- Every stock change leaves an `inventory_movements` row. Cancelling an order returns stock exactly once.
- Order status follows a fixed state machine; merchants can never set payment status.

## Idempotency

`Idempotency-Key` is required on checkout, payment initialisation and refunds. Keys are stored in PostgreSQL, scoped to
`(tenant, scope, key)`; the unique index is the lock. A retry with the same key and body replays the stored response; the
same key with a different body is rejected; failed attempts release the key. Webhook events are separately de-duplicated on
`(tenant, provider, event id)`, and confirmation itself is idempotent.

## Payments

```
Checkout ─► order (PENDING / PENDING) ─► POST /storefront/payments/initialize ─► provider checkout page
   provider ─► redirects the customer back ─► GET /storefront/payments/{ref}/verify ─┐
   provider ─► POST /webhooks/{provider}/{tenant}  (signature verified)  ──► queue ───┤
                                                                                      ▼
                                   ask the provider API for the transaction's real status
                                   check reference, amount and currency against our records
                                   mark payment + order PAID, order → PROCESSING   (idempotent)
```

The browser redirect and the webhook body are **never** treated as proof of payment; only the provider's own API response
is. A mismatch (amount, currency, reference) marks the payment failed and leaves the order unpaid.

## Caching

| What | Where | Key | Invalidated |
|---|---|---|---|
| hostname → tenant id | Laravel cache (Redis) | `tenancy:host:<host>` | domain verify/remove; `TENANCY_CACHE_TTL` (300 s), misses 30 s |
| platform settings | Laravel cache | `platform:settings` | on update |
| storefront data | Next.js data cache | tagged `tenant:<host>`, URL also carries the host | merchant/admin writes (`revalidateTag`), else 60 s |
| API docs | file cache (Scramble) | `scramble.openapi` | `scramble:clear` |

## Security checklist (what the code enforces)

- Authentication: Sanctum bearer tokens (expiring; revoked on password reset, suspension and logout). Merchant, customer and admin tokens only work on their own routes.
- Authorization: policies + permissions (`UserRole::permissions()`), never scattered role checks. Platform admins cannot use merchant routes; merchants cannot reach `/admin`.
- Validation: Form Requests everywhere; existence rules are tenant-scoped.
- Uploads: extension **and** sniffed MIME allow-lists (SVG and other active formats excluded), 5 MB cap, dimension cap.
- Webhooks: signature verification per store credentials (HMAC-SHA512 / secret hash), constant-time comparison, tenant from the URL, never from the body.
- Secrets: payment-gateway keys encrypted at rest, write-only through the API, never logged.
- Rate limiting: per user/IP, stricter on auth; webhook endpoint has its own limit.
- Enumeration: password-reset and newsletter endpoints answer identically whether or not the address exists.
- Every request gets an `X-Request-Id`; logs carry `request_id`, `tenant_id`, `user_id` (Laravel `Context`).
- Platform-admin actions are written to the log as `admin_action` entries (id-only, no secrets).

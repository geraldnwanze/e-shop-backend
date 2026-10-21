# Multi-Tenant E-Commerce Platform: API

A multi-tenant SaaS where merchants create online stores from platform-provided themes. One shared Laravel
API and one shared PostgreSQL database serve every store, with strict server-side tenant isolation.

| | |
|---|---|
| **Backend** (this repo) | Laravel 13 · PHP 8.4 · PostgreSQL · Redis · Sanctum · queues |
| **Frontend** | Next.js 16 (storefronts + merchant dashboard + platform console), in the sibling repo `e-shop-frontend` |
| **Stores live at** | `icecream.mydomain.com` (platform subdomain) or `www.icecream.com` (verified custom domain) |

```
Browser ──► Cloudflare ──► Next.js (storefront / dashboard / console) ──► Laravel API ──► PostgreSQL
                                   resolves nothing itself:              resolves the store     Redis (cache, queues)
                                   forwards the visitor's hostname       from the hostname      Cloudinary / Spaces (media)
                                                                         Paystack / Flutterwave (payments)
```

## Documentation

| Guide | What it covers |
|---|---|
| [docs/architecture.md](docs/architecture.md) | Tenancy model, request flow, caching, queues, security rules |
| [docs/deployment.md](docs/deployment.md) | Production on a small VPS with Docker; native alternative; backups; scaling |
| [docs/cloudflare.md](docs/cloudflare.md) | DNS, wildcard routing, SSL, caching and firewall settings |
| [docs/custom-domains.md](docs/custom-domains.md) | The merchant custom-domain flow, verification, troubleshooting |
| [docs/media-providers.md](docs/media-providers.md) | Cloudinary and DigitalOcean Spaces, switching, adding a provider |
| [docs/payment-providers.md](docs/payment-providers.md) | Paystack and Flutterwave setup, the payment flow, adding a provider |
| `/docs/api` | Interactive OpenAPI reference (Scramble), 112 operations; always on locally, see [API docs](#api-documentation) |
| Frontend `README.md` | Hostname routing, the BFFs, theme system, dashboard and console |

## Quick start

Pick **one** path. All three give you the same API on `http://localhost:8000`.

### A. Docker (everything included)

```bash
cp .env.example .env
docker compose up -d --build
docker compose exec app php artisan migrate --seed
```

Starts PHP-FPM, nginx, PostgreSQL, Redis, a queue worker, the scheduler and Mailpit (http://localhost:8025 catches all email).
Your source is bind-mounted; edits apply immediately. The first start runs `composer install` and generates `APP_KEY`.

### B. Native with PostgreSQL + Redis (closest to production)

Requirements: PHP 8.4 with `pdo_pgsql` and `redis` (phpredis), Composer, and PostgreSQL 15+ / Redis 7+ (installed locally, **or run just those two in Docker**, below).

```bash
# Option 1: PostgreSQL + Redis in Docker, app runs natively
docker compose up -d postgres redis          # db "shop", user "shop", password "secret" on 127.0.0.1:5432; Redis on 127.0.0.1:6379
#   .env: DB_CONNECTION=pgsql DB_HOST=127.0.0.1 DB_PORT=5432 DB_DATABASE=shop DB_USERNAME=shop DB_PASSWORD=secret
#         REDIS_HOST=127.0.0.1  REDIS_PASSWORD=null   (+ CACHE_STORE / QUEUE_CONNECTION / SESSION_DRIVER=redis)
# Option 2: use PostgreSQL/Redis installed on your machine (createdb shop_platform, then set DB_* in .env)

composer install
cp .env.example .env && php artisan key:generate   # skip the copy if you already have a .env

php artisan migrate --seed

php artisan serve                            # API on :8000
php artisan queue:listen --tries=1           # second terminal: queued emails, webhooks, domain checks
php artisan schedule:work                    # third terminal: domain re-verification, unpaid-order expiry
```

### C. Native, minimal (SQLite, no Redis)

Fine for trying things out; use B before trusting concurrency or `LIKE`-search behaviour (PostgreSQL differs from SQLite).

```bash
composer install && cp .env.example .env && php artisan key:generate
# in .env set:  DB_CONNECTION=sqlite  (and remove DB_HOST/DB_DATABASE...)  CACHE_STORE=database  QUEUE_CONNECTION=database  SESSION_DRIVER=database
touch database/database.sqlite
php artisan migrate --seed && php artisan serve
php artisan queue:listen --tries=1
```

### Local hostnames (multi-tenancy needs real hostnames)

Stores are resolved from the hostname. Browsers resolve `*.localhost` to your machine automatically, so use
`PLATFORM_DOMAIN=localhost` in this repo's `.env` and the frontend's `.env`:

| URL | What |
|---|---|
| `http://icecream.localhost:3000` | the seeded demo storefront |
| `http://app.localhost:3000` | merchant dashboard and platform console (log in) |
| `http://localhost:8000/docs/api` | API reference |

Re-seed after changing `PLATFORM_DOMAIN` (`php artisan migrate:fresh --seed`) because each store's subdomain is stored as a domain row.

### Seeded demo accounts (local only)

| Role | Email | Password |
|---|---|---|
| Platform admin | `admin@<PLATFORM_DOMAIN>` (or `SEED_ADMIN_EMAIL`) | `password` |
| Merchant (owner of "Scoops Ice Cream", `icecream`) | `merchant@icecream.test` | `password` |
| Shopper (storefront) | `ada@example.test`, `tunde@example.test` | `password` |

The demo store has 5 products with Cup/Tub variants, 3 categories, discount code `WELCOME10`, and orders in several states.
Demo accounts and the demo store are **never** seeded in production. Plans `free` / `starter` / `pro` and the six themes are required data and are seeded everywhere; in production the platform admin comes from `SEED_ADMIN_PASSWORD` or `php artisan platform:create-admin` ([deployment](docs/deployment.md)).

## Frontend

```bash
cd ../e-shop-frontend
cp .env.example .env            # API_URL=http://localhost:8000/api/v1, PLATFORM_DOMAIN=localhost
npm install && npm run dev      # http://app.localhost:3000  and  http://icecream.localhost:3000
```

See the frontend README for details.

## Testing

```bash
php artisan test --compact                       # whole suite (SQLite in memory: fast)
php artisan test --compact --filter=Checkout     # one area
vendor/bin/pint --format agent                   # code style (run before committing)
```

The suite (430 tests) covers tenant isolation (products, orders, customers, carts, media, domains, settings), hostname
resolution, authentication, products/variants/inventory, checkout, idempotency, payment providers and webhooks, refunds,
plan limits and the whole platform-admin surface.

**Run it against PostgreSQL too**, which is what production uses (it catches case-sensitive `LIKE`, transaction-abort and
locking differences that SQLite hides):

```bash
docker compose exec postgres createdb -U shop shop_test        # once
DB_CONNECTION=pgsql DB_HOST=127.0.0.1 DB_PORT=5432 DB_DATABASE=shop_test DB_USERNAME=shop DB_PASSWORD=secret \
  php artisan test --compact
```

The same 430 tests pass on PostgreSQL 18. Concurrency behaviour (no overselling the last unit; gapless unique order numbers
under parallel checkouts) was additionally verified with parallel PHP processes against PostgreSQL.

## API documentation

Interactive reference generated by [Scramble](https://scramble.dedoc.co) from the code (routes, form requests, resources):

- UI: `http://localhost:8000/docs/api` · JSON: `http://localhost:8000/docs/api.json`
- Local development: always available. **Production: off** unless `API_DOCS_ENABLED=true` (the docs contain no secrets; enable them if you want public API docs).
- Export a static file: `php artisan scramble:export --path=openapi.json`
- Conventions shared by every endpoint (response envelope, error codes, tenant resolution, idempotency, money) are written in
  [resources/docs/api-overview.md](resources/docs/api-overview.md), which is also the introduction of the generated reference.

## Commands and background work

| Command | Purpose | Scheduled |
|---|---|---|
| `php artisan queue:work redis` | Emails, webhook processing, domain verification jobs | run as a service (the `queue` container) |
| `php artisan schedule:work` | Runs the schedule below | run as a service (the `scheduler` container) |
| `domains:verify-pending` | Queue DNS checks for pending / not-yet-routed custom domains | every 5 minutes |
| `orders:expire-unpaid` | Cancel stale unpaid orders and return their stock (`UNPAID_ORDER_EXPIRY_HOURS`, default 24) | hourly |
| `platform:create-admin {email}` | Create or promote a platform administrator (the **only** way to create one) | no |
| `scramble:export` | Export the OpenAPI document | no |

Queue workers scale independently of the web tier (`docker compose ... up -d --scale queue=3`).

## Project layout

```
app/
├── Actions/            # single-purpose business operations (RegisterMerchant, ProvisionStore, ...)
├── Console/Commands/   # scheduled / admin commands
├── Enums/              # roles, permissions, statuses, error codes (no magic strings)
├── Exceptions/         # BaseApiException and the renderer that builds the error envelope
├── Http/
│   ├── Controllers/Api/V1/   # thin: validate -> call service/action -> return ApiResponse
│   ├── Middleware/           # request id, tenant resolution, platform-admin gate, idempotency
│   ├── Requests/             # Form Requests (all validation)
│   └── Resources/            # API Resources (response shapes)
├── Models/             # tenant-owned models use the BelongsToTenant trait
├── Policies/           # authorization (permission + tenant ownership)
├── Services/           # Media, Payments, Domains, Checkout, Inventory, Catalog, Platform, ...
└── Support/Tenancy/    # TenantContext, TenantResolver, DomainNormalizer
```

## Writing tenant-safe code (read before contributing)

Tenant isolation is the highest-priority rule. The scaffolding makes the safe path the default; these are the rules that keep it that way.

1. **Tenant-owned model → `use BelongsToTenant`.** It adds the global `TenantScope` (queries fail closed with no tenant) and assigns `tenant_id` on create. Never add `tenant_id` to `$fillable`.
2. **Look records up through the scope**, never with an unscoped query: `Product::query()->where('id', …)->firstOrFail()` or route-model binding. Both are tenant-scoped; `Product::withoutGlobalScopes()` is for the resolver and tests only.
3. **Never trust the client** for `tenant_id`, `user_id`, prices, totals or payment status. They are derived server-side.
4. **Validation that checks existence must be tenant-scoped**: `Rule::exists('categories', 'id')->where('tenant_id', $tenantId)`.
5. **Queued work has no request.** Events and jobs carry plain **ids** (`tenantId`, `orderId`) and re-enter the tenant with `TenantContext::runAs($tenant, fn () => …)`. Serialized Eloquent models would not be found without a tenant.
6. **Cross-tenant code is explicit.** Only the `/admin` route group runs with the tenant-scope bypass (`EnsurePlatformAdminMiddleware`). Inside it, anything that relies on the scope (e.g. `DomainService::remove`) must run under `runAs($tenant, …)`. Tests assert the bypass never leaks into the next request.
7. **Cache keys include the tenant or hostname**, and cache values never hold another tenant's data.
8. **A foreign UUID behaves like a missing one** (404, not 403) so identifiers cannot be probed.

More detail and the reasoning: [docs/architecture.md](docs/architecture.md).

## Environment

`.env.example` is the reference for local development; `.env.production.example` for the Docker production stack. Highlights:

| Variable | Meaning |
|---|---|
| `PLATFORM_DOMAIN` | Root domain; stores live at `<slug>.<PLATFORM_DOMAIN>`; reserved labels (`app`, `api`, `www`, …) never resolve as stores |
| `FRONTEND_URL` | Dashboard origin (email links for verification and password reset point here) |
| `CUSTOM_DOMAIN_CNAME_TARGET` | The hostname merchants CNAME their domain to (default `shops.<PLATFORM_DOMAIN>`) |
| `TRUSTED_PROXIES` | IP(s) of the Next.js server (or `*` behind a trusted edge) so per-IP rate limits see the visitor |
| `CORS_ALLOWED_ORIGINS`, `…_PATTERNS` | Browser origins allowed to call the API directly (the storefront normally calls it server-side) |
| `MEDIA_DRIVER` | `cloudinary` or `spaces` ([docs](docs/media-providers.md)) |
| `PAYMENTS_USE_PLATFORM_CREDENTIALS` | `false`: stores bring their own keys ([docs](docs/payment-providers.md)) |
| `DEFAULT_PLAN_SLUG` | Plan assigned to newly registered stores (default `free`) |
| `API_DOCS_ENABLED` | Publish `/docs/api` outside local development |

Never commit `.env`, `.env.production` or any credentials.

## Known limitations

- Platform billing: plans and subscriptions are managed by administrators and enforce limits (products, custom domains); there is no merchant-to-platform payment collection yet.
- Tax is always 0; shipping is a per-store flat rate.
- Payment amounts assume currencies with 2 decimals (zero-decimal currencies such as JPY would need a small change in the Flutterwave conversion).
- Refunds accepted by a provider are recorded as completed; later provider-side failures are not tracked.
- Cloudflare automation (DNS / Custom Hostnames API) is not implemented; custom-domain verification uses DNS lookups ([docs](docs/custom-domains.md)).
- Image processing is synchronous (no media-processing job).

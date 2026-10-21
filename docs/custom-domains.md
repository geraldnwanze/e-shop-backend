# Custom domains

Every store is reachable at `<slug>.PLATFORM_DOMAIN` immediately. Merchants can additionally connect their own domain
(`icecream.com`, `www.icecream.com`, `shop.icecream.com`).

## The merchant flow

1. **Add**: dashboard → *Store → Domains* → enter `icecream.com` (a bare hostname or a pasted URL both work).
   `POST /api/v1/domains {"domain": "icecream.com"}`. The domain is stored **pending** with a random verification token.
2. **DNS instructions** are returned (and shown in the dashboard). Two records:

   | Purpose | Type | Name | Value |
   |---|---|---|---|
   | 1. Prove ownership | `TXT` | `_shop-verify.icecream.com` | the 40-character token |
   | 2. Send traffic to the platform | `CNAME` | `www` (or the subdomain) | `CUSTOM_DOMAIN_CNAME_TARGET` (e.g. `shops.mydomain.com`) |

   For an **apex** domain (`icecream.com`) a CNAME is not allowed by DNS; use the provider's CNAME flattening / `ALIAS` / `ANAME` to the same
   target, or an `A` record to an address listed in `CUSTOM_DOMAIN_IPS`.
3. **Verify**: the merchant clicks *Verify* (`POST /domains/{id}/verify`, limited to 10/min), and the scheduler re-checks pending domains
   automatically every 5 minutes (`domains:verify-pending`).
4. Optionally **make it primary** (used for canonical URLs, sitemaps and payment callbacks). Only verified domains can be primary.

## Statuses

| Status | Meaning | Resolves to the store? |
|---|---|---|
| `pending` | Waiting for the TXT record | **No** |
| `verified` | Ownership proven (TXT found), routing not pointing at the platform yet | Yes |
| `active` | Ownership proven **and** routed (CNAME/ALIAS to the target, or A in `CUSTOM_DOMAIN_IPS`) | Yes |
| `failed` | Still unverified 72 hours after it was added; the merchant can retry *Verify* | **No** |

The platform never claims a domain is verified unless the TXT record was actually found by a DNS lookup. Platform administrators cannot
force-verify either; they can only re-run the same check.

If a verified domain later **loses** its TXT record, the next check demotes it (unverified, no longer primary) rather than trusting it.

## Resolution rules

- Hostnames are normalised before storing/looking up: lower-case, protocol/path/port/trailing dot removed.
- `www.x.com` and `x.com` are treated as the same site.
- A domain can belong to **one** tenant, globally. Trying to add a domain another tenant uses returns the same "already in use" message and reveals nothing about the owner.
- Platform hostnames (`*.PLATFORM_DOMAIN`) cannot be added as custom domains; IP addresses and single-label names are rejected.
- Resolution results are cached (`tenancy:host:<host>`); verifying or removing a domain purges its entries immediately.
- The platform subdomain row is created at registration and cannot be removed.

## Limits

Per-tenant custom-domain limits come from the tenant's plan (`limits.custom_domains`; `null` = unlimited). The seeded plans allow
Free 0 / Starter 1 / Pro 5. A tenant with no entitled subscription falls back to `CUSTOM_DOMAIN_MAX` (default 3). Exceeding it returns
`409 PLAN_LIMIT_REACHED`.

## TLS and the last mile

DNS verification makes the *application* serve the store for that hostname. The *connection* still needs a certificate for it:
see [cloudflare.md](cloudflare.md#3-custom-domains-wwwicecreamcom). Registering the hostname with your TLS layer (for example
Cloudflare for SaaS Custom Hostnames) is not automated by this codebase.

## Troubleshooting

```bash
dig +short TXT _shop-verify.icecream.com        # must print the token (quotes are fine)
dig +short CNAME www.icecream.com               # must print shops.mydomain.com.
dig +short A icecream.com                       # apex: must include an address from CUSTOM_DOMAIN_IPS (if you use A records)
php artisan domains:verify-pending              # queue checks now (needs a running queue worker)
```

| Symptom | Likely cause |
|---|---|
| Stays `pending` | TXT record on the wrong name (e.g. `_shop-verify` instead of `_shop-verify.icecream.com` at a provider that appends the zone), or DNS not propagated yet |
| `verified` but never `active` | CNAME/ALIAS missing or pointing elsewhere; proxying records through another CDN hides the CNAME target |
| `active`, but the browser shows a certificate error | Custom-hostname TLS not provisioned (see Cloudflare guide) |
| `failed` | Not verified within 72 hours; fix the records and click *Verify* |
| "This domain is already in use" | Another tenant owns it (or you added it already) |

## Extending: Cloudflare (or other) automation

DNS access goes through the `DnsLookup` interface (`NativeDnsLookup` uses PHP's resolver; tests substitute a fake). The place to
register the hostname with an external service is `DomainVerificationService::verify()`, in the branch where `is_verified` becomes
true. Keep the invariant that nothing is marked verified before a real check succeeded.

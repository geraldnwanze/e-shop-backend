# Cloudflare setup

The platform expects Cloudflare in front of everything: DNS, TLS, and the proxy/CDN. This guide uses `mydomain.com` as
the platform domain (`PLATFORM_DOMAIN`). Replace it with yours.

> **Verified vs. not verified.** The DNS layout, the headers the apps read (`Host`, `CF-Connecting-IP`) and the application
> side of everything below are covered by tests and were exercised locally. The Cloudflare dashboard steps and the Workers
> deployment option are written from Cloudflare's documented behaviour and have **not** been run against a live account
> from this repository. Treat them as a checklist and confirm in your account.

## 1. DNS records

| Name | Type | Target | Proxy | Purpose |
|---|---|---|---|---|
| `api` | A (or CNAME) | your VPS IP | Proxied | The Laravel API (`https://api.mydomain.com`) |
| `app` | CNAME / A | where the web app runs | Proxied | Merchant dashboard and platform console |
| `*` (wildcard) | CNAME / A | the **same** origin as `app` | Proxied | Every store: `icecream.mydomain.com`, `fashion.mydomain.com`, … |
| `shops` | CNAME / A | the same origin as `app` | Proxied | The **custom-domain target** merchants CNAME to |
| `@` (apex) | as you wish | marketing site or redirect | Proxied | Optional |

Notes:

- **No per-store DNS work.** The wildcard record means a new store at `<slug>.mydomain.com` works the moment it is created: the
  backend only has to resolve the hostname to a tenant. Never create DNS records from application code for platform subdomains.
- Explicit records (`api`, `app`, `shops`) always win over the wildcard, so they do not conflict with it.
- `app`, `api`, `www`, `shops` and similar labels are reserved: a merchant cannot register them as a store slug, and they never resolve as stores.
- The web app (one Next.js server) serves **both** `app.mydomain.com` and every store. It tells them apart by hostname (`proxy.ts`), so point `app`, `*` and `shops` at the same origin.
- Set `CUSTOM_DOMAIN_CNAME_TARGET=shops.mydomain.com` in the API (this is the default: `shops.<PLATFORM_DOMAIN>`).

## 2. SSL/TLS

1. **SSL/TLS → Overview → Full (strict).** Install a Cloudflare Origin Certificate (or Let's Encrypt) on the origin.
2. **Edge Certificates → Always Use HTTPS: On**; enable **HSTS** once you are sure everything is HTTPS.
3. Universal SSL covers `mydomain.com` and **first-level** wildcards (`*.mydomain.com`) when the records are proxied. It does **not** cover second-level names such as `a.b.mydomain.com`; the platform never uses those (nested labels are rejected).
4. Set `STOREFRONT_SCHEME=https` on the web app and keep `APP_URL` / `FRONTEND_URL` on `https://…`.

## 3. Custom domains (`www.icecream.com`)

A merchant's own domain is **not** under your Cloudflare zone, so Universal SSL does not cover it. You need one of:

- **Cloudflare for SaaS (Custom Hostnames)**: set `shops.mydomain.com` as the *fallback origin*; each merchant domain is added as a custom hostname and Cloudflare issues and renews its certificate. This is the Cloudflare-native route.
- **Your own TLS termination** for arbitrary hostnames (e.g. a reverse proxy that issues certificates on demand).

What the application already does (see [custom-domains.md](custom-domains.md)): it accepts a domain, gives the merchant DNS instructions,
verifies ownership by DNS, and starts resolving that hostname to the tenant.

What it does **not** do: call the Cloudflare API to register the hostname or provision its certificate. That step is a
manual action (or a small integration you add). The intended extension point is `DomainVerificationService`, right where a
domain becomes verified; the DNS access is behind the `DnsLookup` interface.

> Do not assume a merchant's custom domain works end-to-end until its hostname is registered with your TLS layer and the
> merchant's CNAME points at `shops.mydomain.com`.

## 4. Caching rules

- The default behaviour is right: Cloudflare caches static assets (`/_next/static/*`) and does not cache HTML or JSON.
- **Do not** enable "Cache Everything" on these hostnames. Storefront HTML differs per hostname and carries per-visitor state in the browser, and the `/api/*` routes are private.
- If you add rules, **bypass cache** for `/api/*` on `app.mydomain.com` and every store host.
- Storefront data freshness is handled by Next.js itself (60 s data cache, purged immediately on merchant edits).

## 5. Real visitor IP

Rate limiting is per IP, so the API must see the visitor, not Cloudflare or the web server.

- The Next.js BFF reads `CF-Connecting-IP` (falling back to `X-Forwarded-For`) and forwards it to the API as `X-Forwarded-For`.
- Set `TRUSTED_PROXIES` on the API to the IP(s) of the web server that calls it (or `*` if only a trusted edge can reach the API).
- If Cloudflare also proxies **direct** API traffic (`api.mydomain.com`), uncomment the `set_real_ip_from` / `real_ip_header CF-Connecting-IP` lines in `docker/nginx/default.conf` and list Cloudflare's published IP ranges (https://www.cloudflare.com/ips/).

## 6. Origin protection

- Firewall the VPS so ports 80/443 accept traffic **only from Cloudflare's IP ranges** (and SSH only from you). Otherwise people can bypass Cloudflare and talk to the origin directly.
- Or use **Cloudflare Tunnel** (`cloudflared`) so the origin has no public ports at all.

## 7. Payment webhooks

Paystack/Flutterwave call `https://api.mydomain.com/api/v1/webhooks/{provider}/{tenant-uuid}`. Make sure no WAF rule or
"Bot Fight Mode" setting blocks these server-to-server POSTs (allow-list the provider's IPs or skip challenges on the path
`/api/v1/webhooks/*`). A challenged webhook looks like a failed delivery to the provider. See [payment-providers.md](payment-providers.md).

## 8. Deploying the web app

Options (the web app is one Next.js 16 server):

1. **Docker on the VPS** behind the Cloudflare proxy: supported by the `Dockerfile` / `compose.yaml` in the frontend repo.
2. **Cloudflare Workers** using the OpenNext Cloudflare adapter: not configured in this repository. If you choose it, verify that
   `proxy.ts` (Host-based rewriting), the `/api/shop` and `/api/admin` route handlers (cookies, multipart bodies) and
   `revalidateTag` behave on that runtime before relying on it.

Whichever you pick, `app`, `*` and `shops` must reach the same deployment.

## Checklist

- [ ] `api`, `app`, `*`, `shops` records exist and are proxied
- [ ] SSL mode Full (strict); origin certificate installed
- [ ] `PLATFORM_DOMAIN`, `CUSTOM_DOMAIN_CNAME_TARGET`, `FRONTEND_URL`, `APP_URL` set on the API
- [ ] `PLATFORM_DOMAIN`, `API_URL`, `STOREFRONT_SCHEME=https` set on the web app
- [ ] Origin firewalled to Cloudflare (or tunnel)
- [ ] No cache-everything rules; `/api/*` bypasses cache
- [ ] Webhook path not challenged
- [ ] Plan for custom-domain TLS (Custom Hostnames or your own)

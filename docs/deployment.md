# Production deployment

Target: **one small VPS** (2 vCPU / 2–4 GB RAM is enough to start) running Docker, with Cloudflare in front. The same
images scale out later: move PostgreSQL/Redis to managed services and add queue workers.

> **Status of the Docker files.** `compose.yaml`, `compose.prod.yaml` and the web app's compose file are validated with
> `docker compose config`, and the code they run is exercised by the test suite (including PostgreSQL). The images themselves were
> **not built** in the environment this repository was authored in (no Docker daemon was available), so run through
> [Smoke test](#smoke-test) on your first deploy and expect to fix small things.

## What runs

| Service | Image / target | Role |
|---|---|---|
| `nginx` | `Dockerfile` target `nginx` | Public entry on port 80; serves `public/`, FastCGI to `app` |
| `app` | target `app` (PHP 8.4-FPM) | The Laravel API |
| `queue` | target `app`, `queue:work redis` | Emails, webhook processing, domain checks. **Scale independently** |
| `scheduler` | target `app`, `schedule:work` | `domains:verify-pending` (5 min), `orders:expire-unpaid` (hourly) |
| `postgres` | `postgres:17-alpine` | Primary database (the suite is verified on PostgreSQL 18; the schema uses nothing version-specific) |
| `redis` | `redis:7-alpine`, password + AOF | Cache and queue |

The web app (storefronts + dashboard + console) is a separate deployment: see the frontend README.

## First deploy

### 1. Server

```bash
# Ubuntu 22.04/24.04, as a sudo user
curl -fsSL https://get.docker.com | sh
sudo usermod -aG docker "$USER"            # log out/in
sudo ufw default deny incoming && sudo ufw allow OpenSSH
sudo ufw allow from <cloudflare-range> to any port 80,443 proto tcp   # repeat per range, see docs/cloudflare.md
sudo ufw enable
```

(Docker published ports bypass `ufw` rules by default; prefer binding `HTTP_PORT` to `127.0.0.1` and fronting it with Cloudflare Tunnel or a
host reverse proxy, or manage the `DOCKER-USER` iptables chain. Verify from outside that only the ports you intend are reachable.)

### 2. Configure

```bash
git clone <your repo> shop-api && cd shop-api
cp .env.production.example .env.production
echo "base64:$(openssl rand -base64 32)"        # -> APP_KEY
openssl rand -hex 24                              # -> DB_PASSWORD, REDIS_PASSWORD (one each)
$EDITOR .env.production                           # replace every CHANGE_ME; set domains, mail, media, TRUSTED_PROXIES
chmod 600 .env.production
```

`.env.production` is read both by Compose (interpolation) and by the containers. Never commit it. Key values:

| Variable | Set to |
|---|---|
| `APP_URL` / `FRONTEND_URL` | `https://api.mydomain.com` / `https://app.mydomain.com` |
| `PLATFORM_DOMAIN`, `CUSTOM_DOMAIN_CNAME_TARGET` | `mydomain.com`, `shops.mydomain.com` |
| `TRUSTED_PROXIES` | the IP of the web server that calls the API (or `*` if only a trusted edge reaches it) |
| `CORS_ALLOWED_ORIGINS(_PATTERNS)` | your dashboard origin and (if the browser ever calls the API directly) the store pattern |
| `MAIL_*` | a real SMTP provider (password-reset and verification emails depend on it) |
| `MEDIA_DRIVER` + provider keys | [media-providers.md](media-providers.md) |
| `SEED_ADMIN_EMAIL` / `SEED_ADMIN_PASSWORD` | first platform administrator (remove the password afterwards) |

### 3. Start, migrate, seed

```bash
docker compose --env-file .env.production -f compose.prod.yaml up -d --build
docker compose --env-file .env.production -f compose.prod.yaml run --rm app php artisan migrate --force
docker compose --env-file .env.production -f compose.prod.yaml run --rm app php artisan db:seed --force
```

`db:seed` in production creates only **required data** (the six themes, the Free/Starter/Pro plans) and the platform admin when
`SEED_ADMIN_PASSWORD` is set. Demo data is never seeded in production. Alternatively skip the seeded admin and create one explicitly:

```bash
docker compose --env-file .env.production -f compose.prod.yaml run --rm app php artisan platform:create-admin you@mydomain.com --name="You"
```

Then **remove `SEED_ADMIN_PASSWORD`** from `.env.production`.

### 4. DNS, TLS, web app

Follow [cloudflare.md](cloudflare.md) (records, SSL mode, firewall). Deploy the web app from the frontend repo with
`API_URL=https://api.mydomain.com/api/v1`, `PLATFORM_DOMAIN=mydomain.com`, `STOREFRONT_SCHEME=https`.

### Smoke test

```bash
curl -fsS https://api.mydomain.com/up                                   # 200: the API is up
curl -fsS -H 'Accept: application/json' -H 'X-Storefront-Host: nobody.mydomain.com' \
     https://api.mydomain.com/api/v1/storefront/store | jq .meta.errorCode   # "TENANT_NOT_FOUND"  (the envelope works)
docker compose --env-file .env.production -f compose.prod.yaml ps       # all services healthy/running
docker compose --env-file .env.production -f compose.prod.yaml logs --tail=50 queue scheduler
```

Then in a browser: register a store at `https://app.mydomain.com/register`, open `https://<slug>.mydomain.com`, sign in to
`https://app.mydomain.com/login` as the platform admin, and run **one sandbox payment** ([payment-providers.md](payment-providers.md)).
Check that the verification email arrives.

## Updating

```bash
git pull
docker compose --env-file .env.production -f compose.prod.yaml up -d --build      # replaces containers; queue workers restart on the new code
docker compose --env-file .env.production -f compose.prod.yaml run --rm app php artisan migrate --force
```

Write migrations to be backward-compatible with the previous release (add columns/tables before using them) because
the old containers serve requests until they are replaced. Roll back by checking out the previous tag and running `up -d --build`
(do **not** run `migrate:rollback` in production without reading the migration).

## Operating

| Task | Command |
|---|---|
| Logs | `docker compose … logs -f app queue scheduler` (logs go to stderr) |
| More queue capacity | `docker compose … up -d --scale queue=3` |
| Failed jobs | `docker compose … run --rm app php artisan queue:failed` / `queue:retry all` |
| Run the schedule once | `docker compose … run --rm app php artisan schedule:run` |
| Tinker (read-only habits!) | `docker compose … run --rm app php artisan tinker` |
| Clear/refresh caches | restart `app` (config/routes are cached at container start) |

### Backups

PostgreSQL is the only system of record (Redis holds cache and in-flight jobs; media lives in Cloudinary/Spaces).

```bash
# nightly (cron), then copy off the server
docker compose --env-file .env.production -f compose.prod.yaml exec -T postgres \
  sh -c 'pg_dump -U "$POSTGRES_USER" -d "$POSTGRES_DB" -Fc' > /backups/shop-$(date +%F).dump

# restore into an empty database
docker compose --env-file .env.production -f compose.prod.yaml exec -T postgres \
  sh -c 'pg_restore -U "$POSTGRES_USER" -d "$POSTGRES_DB" --clean --if-exists' < /backups/shop-YYYY-MM-DD.dump
```

**Test a restore** before you need one. Back up the Cloudinary account/Space according to that provider's guidance, and keep
`.env.production` (especially `APP_KEY`: encrypted gateway keys cannot be decrypted without it) in a secrets manager.

### Monitoring

- `GET /up` is the liveness endpoint (the nginx container health-checks it).
- Alert on: failed jobs growing, queue latency, container restarts, disk space, certificate expiry, 5xx rate.
- Every response carries `X-Request-Id`; log lines carry `request_id`, `tenant_id`, `user_id`. Ask users for the request id.

### Scaling out

- **Queue workers**: `--scale queue=N`. They are stateless.
- **Web tier**: run more `app` containers (`--scale app=N`) and restart `nginx` so it re-resolves the `app` service name (nginx resolves it at start). Sessions are not used by the API; tokens are in the database.
- **Database / Redis**: point `DB_HOST` / `REDIS_HOST` at managed services and remove the `postgres` / `redis` services from the compose file. PostgreSQL connections: each PHP-FPM worker and queue worker holds one, so size `max_connections` accordingly (or add PgBouncer in transaction mode).
- **Storage**: already external (Cloudinary/Spaces), so containers are disposable.

## Without Docker

```bash
composer install --no-dev --optimize-autoloader
php artisan migrate --force && php artisan db:seed --force
php artisan config:cache && php artisan route:cache && php artisan event:cache && php artisan view:cache
```

- PHP 8.4 with `pdo_pgsql`, `redis`, `opcache`, `pcntl`; nginx with the `docker/nginx/default.conf` server block adapted (`fastcgi_pass` to your socket/port).
- Queue worker under Supervisor: `php artisan queue:work redis --queue=default --sleep=1 --tries=3 --backoff=10 --max-time=3600`, restart on deploy with `php artisan queue:restart`.
- Scheduler: `* * * * * cd /var/www/app && php artisan schedule:run >> /dev/null 2>&1`.
- `storage/` and `bootstrap/cache/` writable by the PHP user.

## Security checklist

- [ ] `APP_ENV=production`, `APP_DEBUG=false`, a unique `APP_KEY`
- [ ] Strong `DB_PASSWORD`, `REDIS_PASSWORD`; Postgres/Redis **not** published on public ports (the production compose file does not publish them)
- [ ] `.env.production` mode `600`, not in git, backed up securely
- [ ] `TRUSTED_PROXIES` set deliberately (not `*` unless only a trusted edge can reach the API)
- [ ] Origin reachable only through Cloudflare (firewall or Tunnel)
- [ ] `SEED_ADMIN_PASSWORD` removed after the first admin exists; admin accounts protected with strong passwords
- [ ] Real SMTP configured and SPF/DKIM set for the sending domain
- [ ] Payment webhooks reachable and not challenged by a WAF; one sandbox payment per provider tested
- [ ] `API_DOCS_ENABLED` left `false` unless you want public docs
- [ ] Backups run, copied off-site, and a restore has been rehearsed
- [ ] OS and Docker updates scheduled

## Troubleshooting

| Symptom | Check |
|---|---|
| `502` from nginx | `docker compose … logs app`; `app` unhealthy or crashed (bad env, missing `APP_KEY`) |
| 500 right after deploy | Pending migrations: run `migrate --force`; stale caches: restart `app` |
| Emails never arrive | `queue` not running, or SMTP credentials wrong (`queue:failed`) |
| Rate limits hit by everyone at once | The API sees one IP: set `TRUSTED_PROXIES` and make sure the web app forwards `X-Forwarded-For` |
| Stores show "not found" | Wildcard DNS missing; `PLATFORM_DOMAIN` differs between API and web app; store slug reserved |
| Custom domain stuck | [custom-domains.md](custom-domains.md#troubleshooting) |
| Encrypted gateway keys fail after restore | `APP_KEY` differs from the original |

# Operations runbook

## Environments
| | Purpose | Notes |
|---|---|---|
| local | development | `php artisan serve`, SQLite, `*.localhost` subdomains resolve to 127.0.0.1 |
| staging | pre-release | same images as production, separate DB, `PLATFORM_GATEWAY_MODE=test` |
| production | live | Docker stack (`docker-compose.yml`) or Forge/Ploi; zero-downtime deploys |

## Environment checklist (production)
- `APP_ENV=production`, `APP_DEBUG=false` (**never** deploy `.env.example` as-is: `local` enables the sandbox payment page and on-screen dev OTP codes), unique `APP_KEY` (never rotate without re-encrypting gateway credentials)
- `PLATFORM_BASE_DOMAIN=yourbrand.com`, `PLATFORM_CENTRAL_DOMAINS=yourbrand.com,www.yourbrand.com,admin.yourbrand.com`
- Wildcard DNS `*.yourbrand.com` → load balancer; `DOMAIN_PROVISIONER=caddy` (or `cloudflare` + zone/token)
- MySQL 8 (`DB_*`), Redis (`CACHE_STORE=redis`, `QUEUE_CONNECTION=redis`, `SESSION_DRIVER=redis`)
- `TRUSTED_PROXIES` = your load balancer / Caddy addresses only (rate limits rely on the real client IP)
- Payment webhook secrets are **required** for Stripe / MyFatoorah / SkipCash — webhooks without them are rejected
- Mail (`MAIL_*`, SES recommended), platform gateways (`SADAD_*`, `SKIPCASH_*`, `MYFATOORAH_*`, `STRIPE_*`)
- `BACKUP_DISKS=s3` + `AWS_*` for off-site backups
- Queue worker(s) + scheduler running (see compose services `queue`, `scheduler`)
- `php artisan filament:assets && php artisan optimize` on deploy; `npm run build` in the image

## Deploy (zero downtime)
1. Build image (CI) → push.
2. `php artisan down --render="errors::503" --retry=15` is NOT needed for normal releases; run `php artisan migrate --force` (migrations are additive).
3. Roll app, queue and scheduler containers; `php artisan queue:restart`.
4. Smoke test: `/up`, `/admin/login`, a tenant home page, `/api/v1/salon` with `X-Tenant`.

## Custom domains
Owner adds a domain in Dashboard → Settings → Domains (or support does it in /admin → Salon → Domains).
They add the TXT `_luxe.<domain>` token + CNAME (subdomain) or A record (apex). The scheduler re-checks every 10 minutes
(`domains:verify`); on success Caddy issues the certificate on first request (the `/internal/caddy/ask` endpoint only
approves verified domains). "Check now" runs the job synchronously.

## Backups & restore drill (monthly)
- Daily `backup:run` at 02:00 (DB + `storage/app`), `backup:clean` keeps daily backups 30 days, `backup:monitor` alerts.
- Drill on staging: download latest zip → `mysql luxe < db-dumps/mysql-luxe.sql` → restore `storage/app` →
  `php artisan migrate --force` → log in as a demo owner and verify a booking, an invoice PDF and an image.
- Record the drill date and duration in this file.

## Incident checklist
- Payment provider failing: check `payment_webhooks` (status/error), provider status page; webhooks are idempotent so providers can safely retry.
- Messages not delivered: check `campaign_messages`/logs; WhatsApp falls back to SMS automatically.
- Tenant locked unexpectedly: /admin → Salon → Reactivate (logged), then fix the invoice.
- Support needs to see what the owner sees: /admin → Salon → "Login as owner" (reason required; owner sees it in their security log).

## Data protection
- Client data export / deletion: API `GET /api/v1/me/export`, `DELETE /api/v1/me`, or dashboard client actions.
- AI photos are stored encrypted on the private disk and auto-deleted after the retention period unless saved.
- Card data never touches our servers (hosted payment pages only).

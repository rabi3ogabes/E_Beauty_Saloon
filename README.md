# Luxe Salon OS

A luxury, multi-tenant SaaS for beauty salons — sold by the platform to salon owners, run by owners across branches,
loved by their clients. Bookings, POS, online shop, WhatsApp marketing, loyalty and an AI Beauty Studio, on each salon's
own domain, in **Arabic and English**.

> "Luxe Salon OS" is a working name — set `PLATFORM_NAME` to rebrand.

## Highlights
- **Multi-tenancy**: single database, `tenant_id` scoping with automated isolation tests; salons on `{slug}.yourbrand.com`
  or their own verified domain with automatic SSL.
- **Billing engine**: plans + per-branch/per-account modules, 1/3/6/12-month cycles with discounts, coupons, trials,
  prorated upgrades/downgrades, renewal reminders, grace (read-only) and suspension, bilingual PDF invoices with QR.
- **Three Filament panels** in a custom "quiet luxury" theme (light/dark, RTL): `/admin` (platform), `/dashboard`
  (owner & team), `/pos` (cashier, tablet-first, offline-capable).
- **Payments**: SADAD, SkipCash, MyFatoorah, Tap, Stripe (hosted pages, idempotent webhooks), cash, card terminal, COD,
  wallet, gift cards, loyalty points. Money is always integer minor units.
- **Ledgers** for stock, wallet, gift cards and loyalty points — every movement is an immutable row.
- **Support impersonation** across domains with reason logging and an owner-visible security log.
- **API v1** (Sanctum, phone OTP) for white-label Flutter client, staff and owner apps.

## Quick start (local)
Requirements: PHP 8.3+, Composer, Node 22, SQLite (default) or MySQL 8.
```bash
composer install
cp .env.example .env && php artisan key:generate
touch database/database.sqlite
php artisan migrate --seed
npm install && npm run build
php artisan serve
```
| What | URL | Login |
|---|---|---|
| Platform website | http://localhost:8000 | — |
| Super admin | http://localhost:8000/admin | admin@luxesalon.test / password |
| Demo salon website | http://lumiere.localhost:8000 | phone OTP (dev code shown on screen) |
| Owner dashboard | http://lumiere.localhost:8000/dashboard | owner@lumiere.test / password |
| Branch manager | same | manager@lumiere.test / password |
| Receptionist / POS | http://lumiere.localhost:8000/pos | reception@lumiere.test / password (PIN 1234) |
| Stylist | /dashboard | stylist@lumiere.test / password |
| API | http://localhost:8000/api/v1/salon | header `X-Tenant: lumiere` |

Run the queue (`php artisan queue:work`) and scheduler (`php artisan schedule:work`) for reminders, renewals, campaigns
and domain checks.

## Tests & quality
```bash
php vendor/bin/pest          # full suite
vendor/bin/pint              # code style
```
CI runs Pint, Pest and a full seed on every push (`.github/workflows/ci.yml`).

## Documentation
- `docs/CONVENTIONS.md` — architecture, module boundaries, tenancy, money, i18n, Filament gotchas, design language
- `docs/DECISIONS.md` — architecture decision log
- `docs/RUNBOOK.md` — deployment, custom domains, backups & restore drill, incidents
- API reference: `php artisan scribe:generate` → `/docs`
- Mobile apps: `mobile/README.md`

## Production
`docker-compose.yml` runs app (PHP-FPM), queue, scheduler, MySQL, Redis and Caddy (on-demand TLS for every verified
salon domain). See `docs/RUNBOOK.md`.

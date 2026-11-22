# Architecture decisions

Append-only log. Newest last.

1. **Laravel 13 + Filament 5** (latest stable at build time) with three panels: `/admin` (central domains only),
   `/dashboard` and `/pos` (tenant domains only). Panels share one "quiet luxury" theme (`resources/css/filament/admin/theme.css`).
2. **Own single-database tenancy instead of stancl/tenancy.** stancl v3 does not support Laravel 13 yet; our needs
   (tenant_id scoping, domain/subdomain/header identification) are small. `TenantContext` + `BelongsToTenant` +
   `ResolveTenant` middleware. Identification order: custom domain → subdomain → `X-Tenant` header (API only).
3. **Spatie permission with teams = tenant** (`tenant_id` team key). Platform roles live in team `0`. Tenant roles are
   created per tenant on provisioning so each owner can tune the permission matrix.
4. **Pennant with the `array` store** — entitlements are derived from the live subscription (source of truth), so nothing
   is persisted that could go stale. Features: `module:{key}`.
5. **Money = integer minor units** + `Money` value object. Percentages in integer columns are basis points.
6. **Custom impersonation** (instead of lab404) because it must cross domains: one-time 60s token created on the central
   domain, consumed on the tenant domain; every session logged in `impersonation_logs` with reason/start/end.
7. **mPDF instead of dompdf** for invoices/receipts: dompdf cannot shape Arabic glyphs; mPDF does (RTL + joining).
8. **Payment gateways** behind `PaymentGateway` (SADAD, SkipCash, MyFatoorah, Tap, Stripe, offline, fake). Hosted pages only
   (minimal PCI scope). Webhooks are idempotent by `(driver, event_id)` unique key and re-verify with the provider.
9. **Custom domains**: TXT ownership token + CNAME/A check every 10 minutes; SSL via `DomainProvisioner`
   (Caddy on-demand TLS "ask" endpoint, or Cloudflare for SaaS custom hostnames).
10. **Modular route/seed/lang/provider discovery** (`routes/{tenant,central,api,schedules}/*.php`,
    `database/seeders/Demo/*`, `app/Domain/*/*ServiceProvider.php`) so modules never edit shared files.

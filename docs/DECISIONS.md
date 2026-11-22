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
11. **Booking availability engine** (`App\Domain\Booking\Availability\AvailabilityEngine`): pure, deterministic, works in
    UTC unix seconds and converts branch-local hours/schedules with the branch timezone. Multi-service requests are
    chained back-to-back, first trying one staff member for every service, then greedily preferring whoever did the
    previous service. Resource types are checked by *count* (concurrent usage vs. capacity of active resources of that
    type), because `booking_items` stores a single `resource_id`. Staff without any schedule row follow branch hours.
    Cached per (tenant, branch, date, request hash) under a per-branch **version key** bumped by model events
    (bookings, items, holds, schedules, time off, holidays, resources, services) — no cache tags needed.
12. **Booking writes are serialised per tenant** with `Cache::lock` (re-entrant in-process) + a fresh engine re-check
    inside a DB transaction (`HoldSlot`, `CreateBooking`, `RescheduleBooking`). Tenant granularity because staff can
    work at several branches.
13. **Booking payments without changing the shared model**: `PayableBooking` (a `Payable` subclass keeping the
    `Booking` morph class) is passed to `CreateOnlinePayment`; settlement is applied by listeners on
    `PaymentSucceeded` / `PaymentFailed` (`ApplyBookingPayment` recomputes `amount_paid` idempotently from settled
    payments minus refunds and confirms pending bookings; failure cancels the unpaid booking to free the time).
    Late-cancel / no-show fees are percentages of the money collected online (deposit or prepayment).
14. **Staff calendar is a custom CSS-grid Livewire + Alpine page** (FullCalendar's resource-timegrid is a premium
    plugin). Realtime: broadcast-ready `BookingCalendarUpdated` on `private-tenant.{id}.bookings` + 20 s polling.
15. **Shop search** uses a `shop_search_index` table with normalised Arabic/English text (LIKE) instead of Meilisearch;
    swap for Scout later without changing the storefront.
16. **Signed links are host-relative** (driver links, receipts) so they work on subdomains and custom domains alike.
17. **POS tables are prefixed** (`pos_registers`, `pos_shifts`, `pos_cash_movements`, `sale_refunds`); commissions are
    recognised at sale time and reversals in the period they happen.
18. **ResolveTenant is re-entrant**: re-resolving the same tenant swaps in a fresh instance but keeps branch scoping
    (previously route-level `tenant:required` wiped branch restrictions).
19. **Marketing**: platform message prices + shared sender credentials in `platform_message_channels`; tenant channel
    credentials encrypted in `tenant_channel_settings`; quiet hours in `settings.marketing.quiet_hours`; loyalty/referral
    rules in `settings.loyalty.*`. Loyalty points for bookings use idempotency key `loyalty:booking:{id}`.

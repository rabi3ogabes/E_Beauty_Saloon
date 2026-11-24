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
20. **AI Studio**: provider router (own keys → task preference → priority/cost/latency/health) with automatic fallback;
    credits charged only on success; env keys prefixed `AI_` to avoid leaking host variables. Web push sending and real
    content moderation sit behind interfaces (`WebPushSender`, `ModeratesContent`) with safe defaults.
21. **Websites**: themes are Blade + CSS variables (no build per tenant); `/sitemap.xml` and `/robots.txt` are dynamic
    for both central and tenant hosts; salon custom pages live at `/pages/{slug}`.
22. **POS ↔ Marketing**: `SellableRegistry::register(..., onSold:)` lets modules fulfil items sold at the POS (packages
    create active client packages).
23. **Mobile API (Phase 10)** lives in `app/Domain/Api` + `Api/V1` controllers and only orchestrates other modules'
    Actions. One Sanctum guard for three apps: token *abilities* (`client` / `staff` / `owner`) + model type are checked
    by `api.actor:*`, which also applies the team member's branch restrictions. Staff/owner sign in with the dashboard
    password and the same Filament app-MFA secret/recovery codes. Errors share one envelope
    (`ApiResponseEnvelope`, prepended to the `api` group so priority-sorted auth middleware is covered too).
    `Idempotency-Key` replays are stored per tenant + actor in `api_idempotency_keys` (24 h, 2xx only). Push tokens live
    in `client_devices` (unique per tenant) and feed `MarketingMessenger::resolvePushTokenUsing()`. AI Studio photos
    for the app are served from a relative-signed API route on the salon host (image loaders cannot send `X-Tenant`).
    Owner KPIs are a deliberately small query service (`TodayKpis`), not the reporting engine.
24. **Smart layer (Phase 9) — reports**: `App\Domain\Insights\Reports\ReportService` is the single read-only, tenant + branch
    scoped query layer (pure methods, no writes). Dates are grouped in PHP with the tenant/branch timezone so results match on
    SQLite and MySQL (no DB date functions). Revenue = net of refunds, excluding tips (POS by `completed_at`, shop by order time).
    Utilization = booked service minutes ÷ scheduled staff minutes using the availability engine's rules (`Support\Capacity`).
25. **AI insights** are generated nightly per tenant (`GenerateTenantInsights` → `GenerateInsights` inside `TenantContext::run`):
    `MetricsSnapshot` (aggregates only, never client PII) → deterministic `InsightRules` → `InsightCopywriter` (one AI call per
    run for en+ar copy, template fallback) → `insight_cards` upserted by `(tenant, type:scope:ISO-week)`; one `insight_runs` row
    per tenant per local day. One-click actions only create drafts (campaign, purchase order); pricing hints are owner-approved
    records, prices never change automatically.
26. **Ask your data** never lets AI write SQL: the AI (or a keyword fallback planner) returns a JSON plan `{metric, filters, period}`
    that `Ask\QueryCatalog` validates against a whitelist of ReportService methods; filter values are names resolved through the
    tenant's own scoped catalog. Answers are composed by templates from the numbers, which are shown alongside.
27. **Smart-layer AI calls** go through `Insights\Support\SmartText` (AI Studio router, `text` capability, credits debited after
    success with idempotency keys). Small hook: `AbstractDriver::completeText()` exposes a plain completion to other modules.
28. **WhatsApp AI receptionist**: Marketing dispatches `WhatsAppMessageReceived` for every non-STOP inbound message (hook);
    `Assistant` queues `ProcessInboundWhatsApp`, logs the conversation and, when the owner switch + hours allow, runs a
    provider-agnostic tool-calling loop (`AssistantModel`, default JSON protocol over the router). Booking uses the same
    HoldSlot + CreateBooking actions (source `whatsapp_ai`). Handoff on request, low confidence, tool call or AI unavailable.
29. **Operations**: consultation answers are encrypted and signatures stored as PNG on the private `local` disk, streamed via an
    authorised dashboard media route. Client sharing between branches is a tenant setting enforced by a global Client scope that
    only affects branch-restricted team members. Owner alerts are database notifications (+ optional WhatsApp) de-duplicated by
    fingerprint in `owner_alerts`; bad reviews only add the WhatsApp ping because Marketing already notifies low ratings.
30. **Security hardening (review pass)**: platform webhooks accept only configured real gateways, require the payment's
    own gateway, a matching amount and provider confirmation; gateway webhook secrets are mandatory (fail closed); the fake
    gateway only exists in local/testing. POS manager approvals are single-use server tokens (`PosApprovals`), never
    client-supplied user ids. Image uploads are raster-only (no SVG) and `/storage` is served with a sandbox CSP.
    WhatsApp webhooks are processed only for the salon whose secret signed them, and a number belongs to one salon.
    Tenant-configured outbound URLs pass `SafeUrl` (https + public IPs, no redirects). OTP limits key on the normalised
    phone + salon with a daily cap; proxies are trusted only from `TRUSTED_PROXIES`. Review links are signed. CMS rich
    text goes through `safe_html()`. POS idempotency lookups are tenant-scoped.
31. **POS PINs are self-service and unique per salon**: members with POS access set their own PIN on the profile page
    (dashboard and POS panels, current password required); owners can set or clear it from Team logins. A PIN alone
    identifies its holder at the till, so `SetPosPin` refuses one already used in the salon, plus repeated/sequential
    digits. All refusals share one message and are throttled (5/hour) so the check cannot be used to find a colleague's
    PIN. `VerifyPosPin` prefers a holder who has the required permission if older data still shares a PIN.
32. **Password and invite links go to the person's own panel**: `PasswordLinks` replaces Laravel's default
    `password.reset` URL (a route this app never had, so invites and resets crashed) with the signed Filament reset
    URL of the dashboard on the salon's own domain, or of the admin panel for platform staff. A team member who has
    never signed in gets an invitation e-mail, everyone else a reset e-mail.
33. **A live booking hold skips the notice re-check at checkout**: the notice window was enforced when the slot was
    held; minutes spent verifying a phone code must not turn the earliest slot into a misleading "just taken".
    Expired holds, and bookings without a hold, are still checked.
34. **Error monitoring by e-mail, no third party**: `ErrorAlerter` hooks Laravel's exception reporting and e-mails
    `ERROR_ALERT_EMAIL` (production only) with the error, code location, URL/route, salon and user. The same error
    (class + file + line) is sent once per `ERROR_ALERT_THROTTLE` minutes with a repeat count, at most
    `ERROR_ALERT_MAX_PER_HOUR` alerts an hour, and alerting can never throw. Business-rule `DomainException`s are no
    longer reported at all. `php artisan monitoring:test-alert` checks delivery.

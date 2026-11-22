# Luxe Salon OS — mobile API (v1)

Base: `https://{salon-domain}/api/v1` or the platform domain with `X-Tenant: {salon-slug}`.
Reference: `php artisan scribe:generate` → `public/docs` (static HTML ≈ 1 MB, served at `/docs`; generated, not committed —
run it against a seeded database so GET examples are real) + `public/docs/collection.json`
(Postman) + `public/docs/openapi.yaml`. Snapshots of both are committed next to this file:
`docs/api/luxe-salon-api.postman_collection.json` (import into Postman; set `baseUrl`, `X-Tenant` and the Bearer token) and
`docs/api/openapi.yaml`. Regenerate and copy them when endpoints change.

## Conventions

| Topic | Rule |
| --- | --- |
| Tenant | Salon domain / subdomain, or `X-Tenant` header (slug). Tokens only work for the salon that issued them. |
| Auth | Sanctum Bearer tokens. Abilities: `client` (phone OTP), `staff`, `owner` (e-mail + password [+ MFA]). A token only works on its own app's endpoints (`api.actor:*` middleware); wildcard dashboard tokens are not app tokens. |
| Language | `Accept-Language: ar|en` (client profile locale wins once signed in). Responses carry `Content-Language`. |
| Money | `{amount, currency, formatted}` — integer minor units. |
| Time | UTC ISO-8601 (`2026-12-01T08:00:00Z`); booking payloads add `local` (branch date/time/timezone). Post back the exact `starts_at` returned by availability. |
| Errors | `{"message": "…", "error": {"code": "…", "status": 422}, "errors": {…}?, "module": "booking"?}`. Codes: `validation_failed`, `domain_error`, `module_disabled`, `unauthenticated`, `forbidden`, `not_found`, `conflict`, `read_only`, `too_many_requests`, `server_error`, plus auth codes `invalid_credentials`, `mfa_required`, `mfa_invalid`, `app_not_allowed`, `account_disabled`. |
| Modules | Endpoints of a module the plan lacks answer `403` with `module` (booking, shop, marketing, loyalty, ai_studio, pos). |
| Pagination | `?page=&per_page=`; response `meta {current_page, last_page, per_page, total}` (+ `links` on resource collections). |
| Caching | Every GET sends a weak `ETag`; send `If-None-Match` to get `304`. |
| Idempotency | `Idempotency-Key` header on `POST /bookings`, `/bookings/{ref}/cancel|reschedule`, `/shop/orders`, `/packages/{id}/purchase`, `/staff/me/time-off`, `/owner/campaigns`: retries with the same key and body replay the first 2xx response (`Idempotent-Replayed: true`, kept 24 h); same key + different body → 422; concurrent duplicate → 409. |
| Rate limits | `throttle:api` 120/min per user (or IP) per salon; OTP 3/min per phone; staff/owner login 5/min per e-mail + 30/h per IP; booking writes 30/min; AI jobs 20/min. |

## App Review demo login

Store reviewers cannot receive a WhatsApp/SMS code. Per salon, set in tenant settings (dashboard settings JSON or tinker):

```php
$tenant->update(['settings' => array_replace_recursive($tenant->settings ?? [], ['api' => [
    'review_phone' => '+97455550000',   // any format; normalised
    'review_code'  => '424242',         // exactly 6 digits
]])]);
```

`POST /auth/otp` for that phone sends nothing; `POST /auth/verify` with that phone + code signs in (no prior
`/auth/otp` needed). Every other phone uses the normal OTP. The code still goes through `VerifyClientOtp`
(blocked clients stay blocked, logins are logged as `api.review_login`). Remove the settings after the review.

## Endpoint map

| Area | Endpoints |
| --- | --- |
| Catalog (public) | `GET /salon`, `/branches`, `/categories`, `/services`, `/services/{id}`, `/staff` |
| Client auth / profile | `POST /auth/otp`, `/auth/verify`, `/auth/logout`; `GET|PATCH|DELETE /me`, `GET /me/wallet`, `/me/loyalty`, `/me/export` |
| Booking (`booking`) | `GET /availability?branch_id&services[]&staff_id&date&variants[sid]&addons[sid][]`, `POST /bookings/hold`, `POST /bookings`, `GET /bookings?scope`, `GET /bookings/{ref}`, `GET /bookings/{ref}/availability?date`, `POST /bookings/{ref}/cancel`, `POST /bookings/{ref}/reschedule` |
| Shop (`shop`) | `GET /shop/products`, `/shop/products/{id|slug}`, `/shop/categories`, `/shop/fulfilment`, `GET|POST|PATCH /shop/cart…` (`X-Cart-Token`), `POST /shop/orders`, `GET /shop/orders`, `GET /shop/orders/{number}` |
| AI Studio (`ai_studio`) | `GET /studio/styles`, `POST|DELETE /studio/consent`, `POST /studio/jobs` (multipart), `GET /studio/jobs/{id}`, `GET /looks`, `POST /looks/{ref}/save`, `DELETE /looks/{ref}`, signed `GET /studio/looks/{ref}/media/{before|after}` |
| Marketing (`marketing`, `loyalty`) | `GET /packages`, `GET /me/packages`, `POST /packages/{id}/purchase`, `GET /notifications`, `GET /me/referral` |
| Push | `POST /devices`, `DELETE /devices/{token}` |
| Staff app | `POST /staff/auth/login|logout`, `GET /staff/me`, `GET /staff/me/calendar?date`, `POST /staff/bookings/{ref}/check-in`, `GET /staff/me/commissions?from&to`, `GET|POST /staff/me/time-off`, `GET /staff/clients/{id}` |
| Owner app | `POST /owner/auth/login|logout`, `GET /owner/me`, `GET /owner/kpis?branch_id&date`, `GET /owner/alerts`, `GET /owner/segments`, `POST /owner/campaigns` |

## Booking flow (client app)

1. `GET /availability` → pick a slot (`groups.morning[].starts_at`).
2. Optional `POST /bookings/hold` → `hold_token`, totals and `payment_options` (deposit / full / pay_at_salon).
3. `POST /bookings` with `Idempotency-Key` → `201`. For deposit/full: `status=pending_payment` and
   `payment.checkout_url` (hosted page). The booking is confirmed by the payment webhook (same `SettlePayment` →
   `ApplyBookingPayment` path as the website); poll `GET /bookings/{ref}`.

Same Actions and events as the website (`HoldSlot`, `CreateBooking`, `StartBookingPayment`, `CancelBooking`,
`RescheduleBooking`, `CheckInBooking`), `source = app`.

## Push

`POST /devices {token, platform: ios|android|web, app_version?}` after sign-in and on FCM token refresh. Tokens are
unique per salon; re-registering moves a token to the signed-in client. `MarketingMessenger` resolves a client's push
address to the most recently seen device (`ApiServiceProvider` → `MarketingMessenger::resolvePushTokenUsing`). The
staff and owner apps do not register devices yet.

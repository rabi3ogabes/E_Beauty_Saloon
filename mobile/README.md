# Luxe Salon OS — mobile apps

One Flutter codebase, three apps:

| App | Entrypoint | Android flavor | Who |
| --- | --- | --- | --- |
| **Client** (white-label, one store listing per salon) | `lib/main_client.dart` | `client` | Salon clients: book, pay, shop, AI Studio, loyalty |
| **Staff** | `lib/main_staff.dart` | `staff` | Stylists: day calendar, check-in, client notes, commissions, time off |
| **Owner** | `lib/main_owner.dart` | `owner` | Owners: today's KPIs per branch, alerts, quick campaigns |

Stack: Flutter 3.50 / Dart 3.14, Riverpod 3, go_router, Dio, flutter_secure_storage, firebase_messaging (optional), intl + ARB (English and Arabic, with full RTL), google_fonts, cached_network_image, camera + image_picker, url_launcher, share_plus, add_2_calendar. Models are hand-written and immutable, so the build needs no `build_runner` step. The UI imports `package:material_ui` (Flutter ≥ 3.47 moved Material into its own package).

```bash
cd mobile
flutter pub get                   # also generates lib/l10n/generated
flutter run --flavor client -t lib/main_client.dart
flutter run --flavor staff  -t lib/main_staff.dart
flutter run --flavor owner  -t lib/main_owner.dart
flutter analyze && flutter test
```

On iOS, run with `-t lib/main_<app>.dart` and no `--flavor`. The white-label tool sets the iOS bundle id for each app (see below).

## Architecture

```
lib/
  main_client.dart | main_staff.dart | main_owner.dart   → bootstrap(AppFlavor.x)
  app/            bootstrap (preload storage, guarded push init), providers (DI), LuxeApp (theme/l10n/router), flavor
  config/         TenantConfig + tenant_config.g.dart (generated) + Env (dart-defines)
  core/
    api/          ApiClient (Dio) + interceptors: Tenant (X-Tenant, Accept-Language), Auth (Bearer, 401 → session expiry),
                  ETag cache (If-None-Match, 304 → cached 200), ErrorMapping → typed ApiException
    money/        Money (integer minor units; "QAR 149.50" / "149.50 ر.ق")
    storage/      KeyValueStore (secure storage / memory), TokenStore
    theme/        "Quiet luxury" tokens, BrandPalette (build-time + runtime branding), light/plum-dark ThemeData
    widgets/      LuxCard, LuxButton (pill, busy dots), Shimmer skeletons, AppImage, empty/error states, Haptics
    push/         PushService (FCM, guarded: the app runs without Firebase config)
  data/
    models/       hand-written immutable models with lenient JSON parsing
    repositories/ interfaces + Api* implementations (one per backend area)
    mock/         Mock* implementations + demo data for "Maison Lumière"
  features/
    auth/         phone + OTP (one-time-code autofill), shared by all three apps
    client/       onboarding, home, services, booking (controller, slot picker, flow, confirmation, my bookings),
                  shop (cart), studio (consent, camera overlay, polling, before/after), account (wallet, loyalty, profile)
    staff/ owner/ common/
  router/         one GoRouter per flavor (StatefulShellRoute tabs, auth redirects, module gates)
  l10n/           app_en.arb, app_ar.arb, context helpers (money/date/time/duration/error text)
```

Main points:

- **State.** Riverpod `Notifier` and `FutureProvider` only, with no code generation. `BookingFlowController` holds the booking wizard state (branch → services → staff → date/slot → review → submit) and is unit tested.
- **Errors.** Repositories throw only `ApiException`, which has a `kind` (validation/network/moduleDisabled/…), field `errors`, and `module`. `context.errorMessage(e)` turns it into localised text. A 401 on an authenticated request clears the session, and the router then redirects to sign-in.
- **Modules.** `GET /salon` lists the salon's entitled modules. `moduleEnabledProvider('shop')` hides or gates features (booking, shop, ai_studio, loyalty). A 403 response with a `module` key gets the same treatment.
- **Branding.** Colours start from the build-time white-label config, and the runtime `GET /salon` branding (primary/accent/ink/background, logo_url) overrides them.
- **Design.** Ivory `#FAF7F2` and ink `#1C1A17` with champagne `#C8A96A` (dark shade `#A88B4F`) and rose `#E9D6CF` accents. Dark mode uses plum `#1E1620` / `#141014`. Fonts are Playfair Display (headings), Inter (body) and IBM Plex Sans Arabic (Arabic). Cards have a 16 px radius, buttons are pills, borders are hairlines and shadows are soft. Loading states use skeleton shimmers, never a lone spinner. Key actions give haptic feedback, and touch targets are at least 44 px.
- **RTL.** Arabic flips the whole UI. Phone numbers, OTP boxes and the before/after slider stay LTR on purpose.

### Backend endpoints: live vs mocked

Live (in `routes/api/core.php`): `/salon`, `/branches`, `/categories`, `/services`, `/services/{id}`, `/staff`, `/auth/otp`, `/auth/verify`, `/auth/logout`, `/me` (GET/PATCH/DELETE), `/me/wallet`, `/me/loyalty`, `/me/export`.

Other engineers are still building some endpoints. The app codes against the agreed contracts in `Api*Repository` (look for `TODO(backend)`) and uses `Mock*Repository` until `MOCK_PENDING_APIS=false`:

| Area | Endpoints | Repository |
| --- | --- | --- |
| Booking | `GET /availability`, `POST /bookings`, `GET /bookings`, `POST /bookings/{id}/cancel`, `/reschedule` | `BookingRepository` |
| Shop & packages | `GET /products`, `GET /products/{id}`, `POST /orders`, `GET /packages` | `ShopRepository` |
| AI Studio | `GET /studio/styles`, `POST /studio/jobs` (multipart), `GET /studio/jobs/{id}`, `GET /looks` | `StudioRepository` |
| Push | `POST /devices` | `DeviceRepository` |
| Staff | `GET /staff/me/calendar`, `POST /bookings/{id}/check-in`, `GET /staff/me/commissions`, `POST /staff/me/time-off` (assumed) | `StaffRepository` |
| Owner | `GET /owner/kpis`, `GET /owner/alerts`, `POST /owner/campaigns` (stub) | `OwnerRepository` |
| Staff/owner auth | `POST /staff/auth/*`, `/owner/auth/*` (assumed) | `AuthRepository` (prefix per flavor) |

When an endpoint ships, check its JSON against the model's `fromJson`, then build with `--dart-define=MOCK_PENDING_APIS=false`. After every area is live, delete its mock.

### Compile-time switches (`--dart-define`)

| Key | Default | Meaning |
| --- | --- | --- |
| `API_BASE_URL` | from white-label config | Backend origin, without `/api/v1` |
| `TENANT` | from white-label config | Salon slug sent as `X-Tenant` |
| `APP_NAME` | from white-label config | Display name override |
| `MOCK_PENDING_APIS` | `true` | Use mocks for unfinished endpoints |
| `DEMO_MODE` | `false` | Mock everything (no backend needed: design QA, screenshots) |
| `FIREBASE_API_KEY`, `FIREBASE_APP_ID`, `FIREBASE_SENDER_ID`, `FIREBASE_PROJECT_ID` | empty | Optional explicit Firebase options |

## Pointing at a local backend

The Laravel app resolves the tenant from the subdomain or from the `X-Tenant` header, and the app always sends `X-Tenant`.

```bash
# backend (repo root)
php artisan serve --host 0.0.0.0 --port 8000

# iOS simulator / desktop: *.localhost resolves to 127.0.0.1
flutter run --flavor client -t lib/main_client.dart \
  --dart-define=API_BASE_URL=http://lumiere.localhost:8000

# Android emulator: the host machine is 10.0.2.2 (the X-Tenant header selects the salon)
flutter run --flavor client -t lib/main_client.dart \
  --dart-define=API_BASE_URL=http://10.0.2.2:8000 --dart-define=TENANT=lumiere

# physical device on the same Wi-Fi
--dart-define=API_BASE_URL=http://192.168.1.20:8000
```

Debug builds allow cleartext HTTP: Android through `src/debug/AndroidManifest.xml`, iOS through `NSAllowsLocalNetworking`. Release builds require HTTPS. Without a backend, use `--dart-define=DEMO_MODE=true`. In demo mode any 6-digit OTP signs you in, except `000000`, which simulates a wrong code.

## White-label flow

Each salon has a config file at `white_label/<tenant>.json`. The demo is `white_label/lumiere.json` ("Maison Lumière"):

```json
{
  "tenant": "lumiere", "appName": "Maison Lumière", "bundleId": "app.luxesalon.lumiere",
  "apiBaseUrl": "https://lumiere.luxesalon.app", "defaultLocale": "en",
  "colors": { "primary": "#C8A96A", "primaryDark": "#A88B4F", "accent": "#E9D6CF", "ink": "#1C1A17", "background": "#FAF7F2" },
  "splashColor": "#FAF7F2", "splashColorDark": "#141014",
  "logo": "white_label/assets/lumiere/logo.png", "icon": "white_label/assets/lumiere/icon.png",
  "iconForeground": "white_label/assets/lumiere/icon_foreground.png", "iconBackground": "#FAF7F2",
  "supportWhatsapp": "97444000000"
}
```

```bash
cd mobile
# 1. from a committed config …
dart run tool/white_label.dart --tenant lumiere
#    … or bootstrap one from the live salon branding (writes white_label/<slug>.json)
dart run tool/white_label.dart --fetch lumiere --api http://lumiere.localhost:8000 --bundle-id app.luxesalon.lumiere

# 2. icons and native splash, from the generated flutter_launcher_icons.yaml / flutter_native_splash.yaml
dart run flutter_launcher_icons
dart run flutter_native_splash:create

# 3. build
flutter build appbundle --flavor client -t lib/main_client.dart --release
flutter build ipa -t lib/main_client.dart --release
```

The tool writes these files:

- `lib/config/tenant_config.g.dart` (name, slug, API, colours, logo asset, WhatsApp)
- `android/tenant.properties`, which `android/app/build.gradle.kts` reads to set the client `applicationId` and the app label `${appLabel}`
- the iOS `PRODUCT_BUNDLE_IDENTIFIER` in `project.pbxproj` and `CFBundleDisplayName` in `Info.plist`; use `--app staff|owner` to configure iOS for the staff or owner app
- `assets/brand/logo.png`, plus the icon and splash YAML

Pass `--dry-run` to preview the changes. The tool has no package dependencies, so it runs before `pub get`.

The staff and owner apps are shared across salons (`app.luxesalon.staff` / `app.luxesalon.owner`). The user enters a salon code at sign-in, and it is saved and sent as `X-Tenant`. To ship them under a salon's own name, set `staffBundleId` / `ownerBundleId` in that salon's config.

## Push notifications

`PushService` calls `Firebase.initializeApp()` inside a try/catch. When no `google-services.json`, `GoogleService-Info.plist` or Firebase dart-defines are present, push is simply turned off. To turn it on:

1. Create the Firebase apps for each bundle id. Put `google-services.json` in `android/app/` and `GoogleService-Info.plist` in `ios/Runner/` (both are git-ignored, so CI injects them). Add the `com.google.gms.google-services` Gradle plugin.
2. Upload the APNs key to Firebase, then enable the Push Notifications capability in Xcode. `Info.plist` already declares the `remote-notification` background mode.
3. Payloads should set `data.route` (e.g. `/bookings`). A tap opens that route, and in the foreground the app shows a snackbar with a "View" action.

Once a user signs in, the app sends its FCM token to `POST /devices`.

## CI

- **GitHub Actions** (`.github/workflows/mobile.yml`) runs on pushes and PRs that touch `mobile/**`. It runs `pub get`, a format check, `flutter analyze`, `flutter test` and a dry run of the white-label tool. The **build-android** job runs on manual dispatch with inputs `tenant`, `app` and `mock_pending_apis`, and on pushes to `main`. It applies the white-label config, generates icons and splash, signs the APK when `ANDROID_KEYSTORE_*` secrets exist, builds it and uploads it as an artifact.
- **Codemagic** (`codemagic.yaml`) has `android` (AAB → Play internal track) and `ios` (IPA → TestFlight) workflows. Both are parameterised by `TENANT` and `APP` and triggered by `mobile-v*` tags. The comments in that file explain how to hand off to Fastlane if a salon already keeps store metadata there.

## Store publishing checklist (per salon)

- [ ] `white_label/<tenant>.json` committed. Logo is 512 px (transparent) and icon is 1024 px (no transparency for iOS).
- [ ] Bundle id registered (App Store Connect + Play Console). The app name has been checked for trademark conflicts.
- [ ] Icons and splash generated. Splash checked in light and dark mode.
- [ ] Firebase apps created, config files in CI secrets, APNs key uploaded.
- [ ] Signing: Android upload keystore stored in CI secrets (never committed) and Play App Signing enabled. iOS distribution certificate and profile set up through the App Store Connect API key.
- [ ] `MOCK_PENDING_APIS=false` once all endpoints are live. `API_BASE_URL` is the salon's HTTPS domain.
- [ ] Privacy: data-safety form (Play) and privacy nutrition labels (App Store), covering phone, name, photos (AI Studio, used only to generate the preview) and push token. Privacy policy URL is set. In-app account deletion works (Profile → Delete account, which calls `DELETE /me`).
- [ ] Permission strings reviewed (camera, photos, calendar) in English and Arabic.
- [ ] Store listing in English and Arabic: screenshots on 6.7" and 5.5" iPhones and on Android phones, in both languages and in RTL. Use `DEMO_MODE` for clean screenshots.
- [ ] Payments go through the hosted web checkout (`url_launcher`). Explain in the review notes that these are physical services and goods, not digital content.
- [ ] Test account for App Review (a phone number and fixed OTP from the backend's review allow-list).
- [ ] Version and build numbers bumped (`pubspec.yaml` version and CI `--build-number`).
- [ ] Release to the internal track / TestFlight, run a smoke test of book → pay → confirm, then promote.

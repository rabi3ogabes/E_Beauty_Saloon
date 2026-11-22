# Luxe Salon OS — Engineering conventions

Read this before writing code. Every module follows the same rules so the codebase reads as one system.

## Stack
Laravel 13 · PHP 8.3 · Filament 5 (panels `/admin`, `/dashboard`, `/pos`) · Livewire 3 · Blade + Tailwind v4 + Alpine (public sites)
· Pest 4 · Spatie permission (teams = tenant) / activitylog / medialibrary / translatable · Pennant (module entitlements) · Sanctum (API) · mPDF (PDFs, Arabic shaping).

## Layout
```
app/Domain/{Domain}/{Models,Actions,Events,Listeners,Jobs,Notifications,DTOs,Enums,Contracts,...}
app/Domain/{Domain}/{Domain}ServiceProvider.php   <- auto-registered (see AppServiceProvider)
app/Filament/{Admin|Dashboard|Pos}/{Resources,Pages,Widgets}/...   <- auto-discovered recursively
app/Http/Controllers/{Site,Platform,Api/V1,...}
routes/tenant/{module}.php     <- salon website routes (tenant domains only), auto-loaded
routes/central/{module}.php    <- platform website routes (central domains only), auto-loaded
routes/api/{module}.php        <- /api/v1 routes, auto-loaded (already prefixed + named api.v1.)
routes/schedules/{module}.php  <- Schedule:: entries, auto-loaded
database/migrations/2026_0X_..._create_{module}_tables.php
database/seeders/Demo/{Module}DemoSeeder.php     <- auto-run by DatabaseSeeder, ordered by `public static int $order`
lang/{en,ar}/{module}.php      <- one translation file per module (never edit another module's file)
resources/views/{site,platform,filament,pdf,...}/{module}/...
tests/Feature/{Module}/...     <- Pest
```
Never edit another module's files. To add relations onto core models use `Model::resolveRelationUsing()` inside
your `{Domain}ServiceProvider::boot()` (e.g. `Client::resolveRelationUsing('bookings', fn ($c) => $c->hasMany(Booking::class))`).

## Multi-tenancy (single database)
* Every tenant-owned table has an indexed `tenant_id`; branch-owned tables also have `branch_id`.
* Models use `App\Support\Tenancy\BelongsToTenant` (global scope + auto-fill) and, for branch rows, `BelongsToBranch`
  (respects the branch switcher and per-user branch restrictions).
* `tenant()` returns the current tenant. In jobs/commands/tests wrap work in `app(TenantContext::class)->run($tenant, fn () => ...)`.
  Queued jobs must store `tenantId` and call `run()` in `handle()`.
* Pivot/child tables without their own scope must only be reached through a scoped parent.
* Tests must prove tenant A cannot see tenant B (see `tests/Feature/Phase1/TenantIsolationTest.php`).

## Modules & permissions
* Module keys: `booking, pos, shop, marketing, ai_studio, website, loyalty`. Check with `tenant()->hasModule('pos')` or
  route middleware `module:pos`. Filament resources/pages of a module MUST implement:
  ```php
  public static function canAccess(): bool { return tenant()?->hasModule('booking') && auth()->user()?->can('bookings.view'); }
  ```
  (for Resources override `canViewAny()` the same way) so disabled modules are hidden from menus AND blocked.
* Permissions are listed in `App\Support\Permissions\PermissionCatalog` (`{area}.{ability}`). Owners pass every gate.
  If you need a new permission, add it there (merge-friendly: append only inside your area's array).
* Grace period = read-only: guard writes with `abort_if(tenant()->isReadOnly(), 423)` in Actions that mutate from the UI.

## Money
* Integer minor units everywhere (`bigInteger`/`unsignedBigInteger`). Never floats. `App\Support\Money\Money` for maths,
  `money($minor)` helper for display. Filament: `App\Filament\Support\Money::input('price')` / `::column('total')`.
* Percent values stored as basis points when integer (1500 = 15%) — document it in the column comment.

## Translations / RTL
* All UI strings via `__('{module}.key')` with **both** `lang/en` and `lang/ar` files. Bilingual content fields are JSON
  via spatie translatable (`public array $translatable = ['name']`), edited with `App\Filament\Support\Translatable::make('name')`.
* Use logical CSS (`ms-`, `me-`, `ps-`, `start-`, `end-`, `text-start`). Never `ml-`/`left-` for layout. Test Arabic.

## Business logic
* One public `handle()` per Action class, in `app/Domain/{Domain}/Actions`. Controllers, Livewire, Filament and API call Actions.
* Money/stock movements: `DB::transaction` + idempotency keys. Stock ONLY via `StockLedger::move()`;
  wallet via `WalletLedger`, gift cards via `GiftCardLedger`, points via `LoyaltyLedger`.
* Online payments: `CreateOnlinePayment` (salon gateway) → hosted page → webhook → `SettlePayment` → your model implements
  `App\Domain\Payments\Contracts\Payable::onPaymentSucceeded()` / `onPaymentFailed()`. Refunds: `RefundPayment`.
  Tests use `GatewayManager::fake()`.
* Throw `App\Support\Exceptions\DomainException` with a translated message for business-rule errors
  (rendered as 422 JSON for API, flash error for web; in Filament catch and show a Notification).
* Log important actions: `activity()->performedOn($model)->withProperties([...])->log('booking.cancelled')`.
* Model events: prefer explicit Events + Listeners registered in your ServiceProvider.

## Filament 5 gotchas (important)
* Closures are injected **by parameter name**: use `$query`, `$data`, `$record`, `$state`, `$get`, `$set`, `$livewire`,
  `$component`. `fn (Builder $q)` resolves an EMPTY builder from the container and breaks.
* Schemas: `Filament\Schemas\Schema`, layout in `Filament\Schemas\Components\{Section,Grid,Tabs,Wizard,Text,...}`,
  fields in `Filament\Forms\Components\*`, actions in `Filament\Actions\*` (`->recordActions()`, `->toolbarActions()`,
  `->headerActions()`), modal forms use `->schema([...])`, `Get`/`Set` from `Filament\Schemas\Components\Utilities`.
* Look at `app/Filament/Admin/Resources/Tenants` and `.../Plans` for the house style (labels via `__()`, navigation group via
  `getNavigationGroup()` returning `__('salon.nav.xxx')`, model labels translated, empty states with a next action).
* Dashboard navigation groups (in order): today, bookings, sales, clients, catalog, inventory, shop, marketing, ai_studio,
  website, team, reports, settings — use `__('salon.nav.<key>')`.

## Design language — "quiet luxury"
Ivory `#FAF7F2`, ink `#1C1A17`, champagne gold `#C8A96A` (dark `#A88B4F`), rose `#E9D6CF`, plum (dark mode) `#1E1620`,
success `#4F7A5A`, warning `#C48A3A`, danger `#A4473F`. Playfair Display (headings) · Inter (UI) · IBM Plex Sans Arabic.
16px cards, 24px hero cards, 10px inputs, pill buttons, hairline borders, very soft shadows, 150–250ms ease-out.
Public site utilities in `resources/css/app.css`: `container-luxe, eyebrow, btn-primary, btn-gold, btn-ghost, card,
card-hover, input, label, chip, chip-active, skeleton, hairline, glass`. Layouts: `<x-layouts.site>` (salon, tenant
branded) and `<x-layouts.platform>`. Mobile-first, touch targets ≥ 44px, skeletons (never a lone spinner), helpful empty
states, short confident copy, full RTL.

## Tests
* Pest, `tests/Feature/{Module}`. Helpers in `tests/Pest.php`: `createTenant($plan, $branches, $modules)`, `asTenant()`,
  `tenantUrl($tenant, $path)`, `centralUrl()`, `tenantUser($tenant, $role, $branchIds)`, `platformAdmin()`.
* Every Action gets feature tests; every acceptance-checklist item gets a test named after it.
* Run: `php vendor/bin/pest` (whole suite must stay green).

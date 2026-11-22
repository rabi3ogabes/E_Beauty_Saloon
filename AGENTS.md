# Luxe Salon OS

Multi-tenant luxury salon SaaS (Laravel 13, Filament 5, Pest). Read `docs/CONVENTIONS.md` before changing code and append
architectural decisions to `docs/DECISIONS.md`.

- Tests: `php vendor/bin/pest` (must stay green)
- Fresh demo: `php artisan migrate:fresh --seed` → admin@luxesalon.test / password (http://localhost:8000/admin),
  owner@lumiere.test / password (http://lumiere.localhost:8000/dashboard)
- Assets: `npm run build`

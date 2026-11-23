<?php

namespace App\Support\Account;

/**
 * Registry for the client "My Account" area on the salon website. Modules register sections in their
 * ServiceProvider::boot():
 *
 *   app(AccountSections::class)->register('bookings', fn () => __('booking.account.title'), 'calendar',
 *       fn ($client) => view('site.booking.account', [...]), order: 10, module: 'booking');
 */
class AccountSections
{
    /** @var array<string, array{label: \Closure, icon: string, render: \Closure, order: int, module: ?string}> */
    protected array $sections = [];

    public function register(string $key, \Closure $label, string $icon, \Closure $render, int $order = 50, ?string $module = null): void
    {
        $this->sections[$key] = compact('label', 'icon', 'render', 'order', 'module');
    }

    /** Sections visible for the current tenant (module-gated), ordered. */
    public function available(): array
    {
        return collect($this->sections)
            ->filter(fn ($s) => $s['module'] === null || tenant()?->hasModule($s['module']))
            ->sortBy('order')
            ->all();
    }

    public function get(string $key): ?array
    {
        return $this->available()[$key] ?? null;
    }
}

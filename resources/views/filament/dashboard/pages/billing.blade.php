<x-filament-panels::page>
    @php($sub = $this->getSubscription())
    @php($tenant = tenant())
    <div class="grid gap-4 lg:grid-cols-3">
        <section class="rounded-3xl border border-gray-200 bg-white p-6 shadow-sm lg:col-span-2 dark:border-white/10 dark:bg-gray-900">
            @if ($sub)
                <div class="flex flex-wrap items-start justify-between gap-4">
                    <div>
                        <p class="text-xs font-medium uppercase tracking-[0.2em] text-gray-500">{{ __('workspace.billing.current_plan') }}</p>
                        <h2 class="mt-1 font-serif text-3xl text-gray-950 dark:text-white">{{ $sub->plan?->name }}</h2>
                        <p class="mt-1 text-sm text-gray-500">{{ trans_choice('salon.billing.cycle', $sub->cycle_months, ['count' => $sub->cycle_months]) }} · {{ money((int) ($sub->price_snapshot['total'] ?? 0), $sub->price_snapshot['currency'] ?? $tenant->currency) }}</p>
                    </div>
                    <x-filament::badge :color="$sub->status->getColor() ?? 'gray'" size="lg">{{ $sub->status->getLabel() }}</x-filament::badge>
                </div>
                <dl class="mt-6 grid grid-cols-2 gap-4 sm:grid-cols-3">
                    <div class="rounded-2xl bg-gray-50 p-4 dark:bg-white/5">
                        <dt class="text-xs text-gray-500">{{ __('fields.branches') }}</dt>
                        <dd class="mt-1 text-xl font-semibold text-gray-950 dark:text-white">{{ \App\Domain\Branches\Actions\CreateBranch::count() }} / {{ $sub->branches_count }}</dd>
                    </div>
                    <div class="rounded-2xl bg-gray-50 p-4 dark:bg-white/5">
                        <dt class="text-xs text-gray-500">{{ __('workspace.billing.renews') }}</dt>
                        <dd class="mt-1 text-xl font-semibold text-gray-950 dark:text-white">{{ $sub->ends_at?->translatedFormat('d M Y') ?? '—' }}</dd>
                        @if ($sub->ends_at)<dd class="text-xs text-gray-500">{{ $sub->ends_at->diffForHumans() }}</dd>@endif
                    </div>
                    <div class="col-span-2 rounded-2xl bg-gray-50 p-4 sm:col-span-1 dark:bg-white/5">
                        <dt class="text-xs text-gray-500">{{ __('workspace.billing.auto_renew') }}</dt>
                        <dd class="mt-1 text-xl font-semibold text-gray-950 dark:text-white">{{ $sub->auto_renew ? __('salon.ui.yes') : __('salon.ui.no') }}</dd>
                    </div>
                </dl>
                <div class="mt-5">
                    <p class="text-xs text-gray-500">{{ __('fields.modules') }}</p>
                    <div class="mt-2 flex flex-wrap gap-2">
                        @forelse ($sub->modules ?? [] as $module)
                            <x-filament::badge color="primary">{{ __('salon.modules.'.$module) }}</x-filament::badge>
                        @empty
                            <span class="text-sm text-gray-500">—</span>
                        @endforelse
                    </div>
                </div>
            @else
                <p class="text-gray-600 dark:text-gray-300">{{ __('workspace.billing.no_subscription') }}</p>
            @endif
        </section>
        <section class="rounded-3xl border border-gray-200 bg-white p-6 shadow-sm dark:border-white/10 dark:bg-gray-900">
            <x-filament::icon icon="heroicon-o-building-library" class="h-6 w-6 text-primary-600" />
            <h3 class="mt-3 font-semibold text-gray-950 dark:text-white">{{ __('workspace.billing.how_to_pay') }}</h3>
            <p class="mt-2 text-sm text-gray-600 dark:text-gray-300">{{ __('workspace.billing.how_to_pay_body') }}</p>
            @if ($this->getOnlineDrivers())
                <p class="mt-3 text-sm text-gray-600 dark:text-gray-300">{{ __('workspace.billing.online_available') }}</p>
            @endif
        </section>
    </div>

    {{ $this->table }}
</x-filament-panels::page>

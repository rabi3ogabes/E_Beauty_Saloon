<x-filament-panels::page>
    <div class="grid gap-6 md:grid-cols-4">
        <x-filament::section class="md:col-span-1">
            <p class="text-xs uppercase tracking-wide text-gray-500">{{ __('ai_studio.dashboard.balance') }}</p>
            <p @class(['mt-2 text-4xl font-semibold', 'text-danger-600' => $wallet->isLow()])>{{ number_format($wallet->balance) }}</p>
            <p class="mt-1 text-xs text-gray-500">{{ __('ai_studio.dashboard.monthly_included') }}: {{ number_format($wallet->monthly_included) }}</p>
        </x-filament::section>
        <x-filament::section>
            <p class="text-xs uppercase tracking-wide text-gray-500">{{ __('ai_studio.dashboard.jobs_month') }}</p>
            <p class="mt-2 text-3xl font-semibold">{{ number_format($stats['jobs']) }}</p>
        </x-filament::section>
        <x-filament::section>
            <p class="text-xs uppercase tracking-wide text-gray-500">{{ __('ai_studio.dashboard.credits_month') }}</p>
            <p class="mt-2 text-3xl font-semibold">{{ number_format($stats['credits']) }}</p>
        </x-filament::section>
        <x-filament::section>
            <p class="text-xs uppercase tracking-wide text-gray-500">{{ __('ai_studio.dashboard.success_rate') }}</p>
            <p class="mt-2 text-3xl font-semibold">{{ $stats['success'] === null ? '—' : $stats['success'].'%' }}</p>
        </x-filament::section>
    </div>

    <x-filament::section :heading="__('ai_studio.wallet.buy_credits')">
        @if ($packs->isEmpty())
            <p class="text-sm text-gray-500">{{ __('ai_studio.dashboard.packs_empty') }}</p>
        @else
            <div class="grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
                @foreach ($packs as $pack)
                    <div @class(['rounded-xl border p-5', 'border-primary-500 ring-1 ring-primary-500' => $pack->is_featured, 'border-gray-200 dark:border-white/10' => ! $pack->is_featured])>
                        <p class="text-sm text-gray-500">{{ $pack->name }}</p>
                        <p class="mt-1 text-2xl font-semibold">{{ number_format($pack->credits) }} <span class="text-sm font-normal text-gray-500">cr</span></p>
                        <p class="mt-1 text-sm">{{ money($pack->price, $pack->currency) }}</p>
                        @if ($canBuy)
                            <x-filament::button class="mt-4 w-full" size="sm" wire:click="buy({{ $pack->id }})" wire:loading.attr="disabled">
                                {{ __('ai_studio.dashboard.buy_pack', ['credits' => number_format($pack->credits)]) }}
                            </x-filament::button>
                        @endif
                    </div>
                @endforeach
            </div>
        @endif
    </x-filament::section>

    <div class="grid gap-6 lg:grid-cols-3">
        <x-filament::section class="lg:col-span-2" :heading="__('ai_studio.dashboard.transactions')">
            <table class="w-full text-sm">
                <thead><tr class="text-xs uppercase tracking-wide text-gray-500">
                    <th class="py-2 text-start">{{ __('salon.ui.created') }}</th><th class="py-2 text-start">{{ __('ai_studio.dashboard.type') }}</th>
                    <th class="py-2 text-start">{{ __('fields.description') }}</th><th class="py-2 text-end">{{ __('ai_studio.dashboard.amount') }}</th>
                    <th class="py-2 text-end">{{ __('ai_studio.dashboard.balance_after') }}</th></tr></thead>
                <tbody>
                    @forelse ($transactions as $tx)
                        <tr class="border-t border-gray-100 dark:border-white/5">
                            <td class="py-2 text-gray-500">{{ $tx->created_at->diffForHumans() }}</td>
                            <td class="py-2">{{ $tx->type }}</td>
                            <td class="py-2">{{ $tx->description }}</td>
                            <td @class(['py-2 text-end font-medium', 'text-success-600' => $tx->amount > 0])>{{ $tx->amount > 0 ? '+' : '' }}{{ number_format($tx->amount) }}</td>
                            <td class="py-2 text-end text-gray-500">{{ number_format($tx->balance_after) }}</td>
                        </tr>
                    @empty
                        <tr><td colspan="5" class="py-6 text-center text-gray-500">—</td></tr>
                    @endforelse
                </tbody>
            </table>
        </x-filament::section>
        <x-filament::section :heading="__('ai_studio.dashboard.top_styles')">
            <ul class="space-y-2 text-sm">
                @forelse ($topStyles as $row)
                    <li class="flex justify-between"><span>{{ $row->style?->name ?? '—' }}</span><span class="text-gray-500">{{ $row->uses }}</span></li>
                @empty
                    <li class="text-gray-500">—</li>
                @endforelse
            </ul>
        </x-filament::section>
    </div>
</x-filament-panels::page>

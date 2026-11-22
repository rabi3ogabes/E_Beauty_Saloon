<x-filament-panels::page>
    <div class="grid gap-6 xl:grid-cols-[1fr_20rem]">
        <form wire:submit="save" class="space-y-6">
            {{ $this->form }}
            <div><x-filament::button type="submit" icon="heroicon-o-check">{{ __('marketing.common.save') }}</x-filament::button></div>
        </form>

        <div class="space-y-6">
            <x-filament::section :heading="__('marketing.channel_settings.wallet')" icon="heroicon-o-wallet">
                <p class="text-xs uppercase tracking-wider text-gray-500">{{ __('marketing.channel_settings.balance') }}</p>
                <p class="mt-1 font-display text-3xl font-semibold text-gray-950 dark:text-white">{{ money($balance) }}</p>
                @if ($balance < 5000)<p class="mt-2 text-sm text-warning-600">{{ __('marketing.channel_settings.low') }}</p>@endif
                @if ($prices->isNotEmpty())
                    <dl class="mt-4 space-y-1 text-sm">
                        @foreach ($prices as $channel => $price)
                            <div class="flex justify-between"><dt class="text-gray-500">{{ __('marketing.channels.'.$channel) }}</dt><dd>{{ money((int) $price) }}</dd></div>
                        @endforeach
                    </dl>
                @endif
            </x-filament::section>
            <x-filament::section :heading="__('marketing.channel_settings.transactions')">
                @forelse ($transactions as $t)
                    <div class="flex items-center justify-between border-b border-gray-100 py-2 text-sm last:border-0 dark:border-white/5">
                        <span>{{ __('marketing.credits.types.'.$t->type) }}<span class="block text-xs text-gray-500">{{ $t->created_at->diffForHumans() }}</span></span>
                        <span @class(['font-medium tabular-nums', 'text-success-600' => $t->amount > 0, 'text-danger-600' => $t->amount < 0])>{{ $t->amount > 0 ? '+' : '' }}{{ money($t->amount) }}</span>
                    </div>
                @empty
                    <p class="text-sm text-gray-500">—</p>
                @endforelse
            </x-filament::section>
        </div>
    </div>
</x-filament-panels::page>

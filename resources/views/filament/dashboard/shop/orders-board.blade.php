@php
    $columns = $this->columns();
    $palette = ['pending' => '#C48A3A', 'confirmed' => '#A88B4F', 'preparing' => '#C8A96A', 'ready_for_pickup' => '#4F7A5A', 'out_for_delivery' => '#6B7FA3', 'delivered' => '#4F7A5A', 'cancelled' => '#A4473F', 'returned' => '#7A736A'];
@endphp
<x-filament-panels::page>
    <div class="flex flex-wrap items-center gap-3">
        <div class="min-w-64 flex-1">
            <x-filament::input.wrapper prefix-icon="heroicon-o-magnifying-glass">
                <x-filament::input type="search" wire:model.live.debounce.400ms="search" :placeholder="__('shop.admin.board.search')" />
            </x-filament::input.wrapper>
        </div>
        <x-filament::input.wrapper>
            <x-filament::input.select wire:model.live="branch">
                <option value="">{{ __('shop.admin.board.all_branches') }}</option>
                @foreach ($this->branches() as $b)<option value="{{ $b->id }}">{{ $b->name }}</option>@endforeach
            </x-filament::input.select>
        </x-filament::input.wrapper>
        <p class="text-xs text-gray-500">{{ __('shop.admin.board.older_hidden') }}</p>
    </div>

    <div x-data="{ dragging: null, over: null }" class="relative -mx-4 flex snap-x gap-4 overflow-x-auto px-4 pb-4" wire:loading.class="opacity-70">
        @foreach ($columns as $status => $orders)
            @php $enum = \App\Domain\Shop\Enums\OrderStatus::from($status); @endphp
            <section class="flex w-72 shrink-0 snap-start flex-col rounded-2xl bg-gray-50 p-3 ring-1 ring-gray-950/5 transition dark:bg-white/5 dark:ring-white/10"
                     :class="over === '{{ $status }}' ? 'ring-2 !ring-primary-500' : ''"
                     @dragover.prevent="over = '{{ $status }}'" @dragleave="over = null"
                     @drop.prevent="over = null; if (dragging && dragging.from !== '{{ $status }}') $wire.moveOrder(dragging.id, '{{ $status }}'); dragging = null"
                     aria-label="{{ $enum->label() }}" data-status="{{ $status }}">
                <header class="mb-3 flex items-center justify-between px-1">
                    <h2 class="flex items-center gap-2 text-sm font-semibold">
                        <span class="h-2.5 w-2.5 rounded-full" style="background: {{ $palette[$status] }}"></span>{{ $enum->label() }}
                    </h2>
                    <span class="rounded-full bg-white px-2 py-0.5 text-xs text-gray-500 ring-1 ring-gray-950/5 dark:bg-white/10">{{ $orders->count() }}</span>
                </header>
                <div class="flex min-h-24 flex-1 flex-col gap-2">
                    @forelse ($orders as $order)
                        <article wire:key="order-{{ $order->id }}" draggable="true"
                                 @dragstart="dragging = { id: {{ $order->id }}, from: '{{ $status }}' }; $event.dataTransfer.effectAllowed = 'move'" @dragend="dragging = null"
                                 class="cursor-grab rounded-xl bg-white p-3 text-sm shadow-sm ring-1 ring-gray-950/5 transition hover:shadow-md active:cursor-grabbing dark:bg-gray-900 dark:ring-white/10">
                            <div class="flex items-start justify-between gap-2">
                                <a href="{{ $this->orderUrl($order) }}" class="font-semibold hover:underline">{{ $order->number }}</a>
                                <span class="text-xs text-gray-500">{{ $order->created_at->diffForHumans(short: true) }}</span>
                            </div>
                            <p class="mt-1 truncate">{{ $order->customer_name }}</p>
                            <div class="mt-2 flex flex-wrap items-center gap-1.5 text-xs">
                                <span class="rounded-full bg-gray-100 px-2 py-0.5 dark:bg-white/10">{{ __('shop.fulfilment.'.$order->fulfilment) }}</span>
                                <span class="rounded-full bg-gray-100 px-2 py-0.5 dark:bg-white/10">{{ __('shop.payment_methods.'.$order->payment_method) }}</span>
                                @if ($order->gift_wrap)<span class="rounded-full px-2 py-0.5" style="background:#E9D6CF">✦</span>@endif
                                @if ($order->cod_otp_required && ! $order->cod_otp_verified_at && $order->status->value === 'pending')<span class="rounded-full bg-amber-100 px-2 py-0.5 text-amber-800">OTP</span>@endif
                            </div>
                            <div class="mt-2 flex items-center justify-between text-xs text-gray-500">
                                <span>{{ __('shop.account.items', ['count' => $order->items_count]) }}@if ($order->driver) · {{ $order->driver->name }}@endif</span>
                                <span class="font-semibold text-gray-950 dark:text-white">{{ money($order->total, $order->currency) }}</span>
                            </div>
                            @if ($order->status->transitions())
                                <label class="sr-only" for="move-{{ $order->id }}">{{ __('shop.admin.actions.move') }}</label>
                                <select id="move-{{ $order->id }}" class="mt-2 w-full rounded-lg border-gray-200 bg-transparent py-1 text-xs dark:border-white/10"
                                        @change="if ($event.target.value) $wire.moveOrder({{ $order->id }}, $event.target.value); $event.target.value = ''">
                                    <option value="">{{ __('shop.admin.actions.move') }}</option>
                                    @foreach ($order->status->transitions() as $next)
                                        @continue($next->value === 'ready_for_pickup' && ! $order->isPickup())
                                        @continue($next->value === 'out_for_delivery' && ! $order->isDelivery())
                                        <option value="{{ $next->value }}">{{ $next->label() }}</option>
                                    @endforeach
                                </select>
                            @endif
                        </article>
                    @empty
                        <p class="rounded-xl border border-dashed border-gray-300 px-3 py-6 text-center text-xs text-gray-400 dark:border-white/10">{{ __('shop.admin.board.empty') }}</p>
                    @endforelse
                </div>
            </section>
        @endforeach
    </div>
</x-filament-panels::page>

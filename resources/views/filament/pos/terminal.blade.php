@php
    $totals = $this->totals;
    $cur = $this->currency();
    $client = $this->client;
    $shift = $this->shift;
    $branch = $this->branch;
    $staffList = $this->staff;
    $canDiscount = auth()->user()->can('pos.discount');
@endphp

<div class="pos-shell"
     x-data="{
        focusSearch() { this.$refs.search?.focus(); this.$refs.search?.select(); },
        onKey(e) {
            const typing = ['INPUT','TEXTAREA','SELECT'].includes(e.target.tagName);
            if (e.key === 'Escape') { $wire.closeModal(); return; }
            if (e.key === 'F2' || (e.key === '/' && ! typing)) { e.preventDefault(); this.focusSearch(); }
            if (e.key === 'F4') { e.preventDefault(); $wire.tenderCash(); }
            if (e.key === 'F6') { e.preventDefault(); $wire.openModal('bookings'); }
            if (e.key === 'F7') { e.preventDefault(); $wire.openModal('client'); }
            if (e.key === 'F8' || (e.key === 'Enter' && (e.ctrlKey || e.metaKey))) { e.preventDefault(); $wire.complete(); }
        },
        toggleTheme() { $dispatch('theme-changed', document.documentElement.classList.contains('dark') ? 'light' : 'dark') },
     }"
     x-on:keydown.window="onKey($event)"
     x-init="$nextTick(() => focusSearch())">

    @if ($this->registers->isEmpty())
        {{-- No register yet --}}
        <div class="pos-card pos-hero" style="max-width: 560px; margin: 48px auto">
            <x-filament::icon icon="heroicon-o-computer-desktop" class="mx-auto h-10 w-10 text-primary-500" />
            <h2 class="pos-display" style="font-size: 1.8rem; margin: 12px 0 6px">{{ __('pos.register.none_title') }}</h2>
            <p class="pos-muted">{{ __('pos.register.none_body') }}</p>
            @if (auth()->user()->can('pos.close_shift') && \Illuminate\Support\Facades\Route::has('filament.dashboard.resources.pos-registers.index'))
                <a class="pos-btn pos-gold" style="margin-top: 16px" href="{{ route('filament.dashboard.resources.pos-registers.index') }}">{{ __('pos.register.create') }}</a>
            @endif
        </div>
    @elseif (! $shift)
        {{-- Open the drawer --}}
        <div class="pos-card pos-hero" style="max-width: 560px; margin: 48px auto">
            <p class="pos-eyebrow">{{ $branch?->name }}</p>
            <h2 class="pos-display" style="font-size: 2rem; margin: 6px 0">{{ __('pos.shift.open_title') }}</h2>
            <p class="pos-muted">{{ __('pos.shift.open_body') }}</p>
            <div class="pos-stack" style="margin-top: 20px; text-align: start">
                @if ($this->registers->count() > 1)
                    <label class="pos-muted">{{ __('pos.register.label') }}
                        <select class="pos-input" wire:change="selectRegister($event.target.value)">
                            @foreach ($this->registers as $r)
                                <option value="{{ $r->id }}" @selected($r->id === $registerId)>{{ $r->branch?->name }} · {{ $r->name }}</option>
                            @endforeach
                        </select>
                    </label>
                @endif
                <label class="pos-muted">{{ __('pos.shift.opening_float') }} ({{ $cur }})
                    <input class="pos-input" style="font-size: 1.4rem; text-align: center" inputmode="decimal" wire:model="openingFloat" wire:keydown.enter="openShift">
                </label>
                <button type="button" class="pos-primary" wire:click="openShift" wire:loading.attr="disabled">{{ __('pos.shift.open') }}</button>
                <button type="button" class="pos-btn" wire:click="switchUser">{{ __('pos.switch.title') }}</button>
            </div>
        </div>
    @else
        <div class="pos-grid">
            {{-- ═════════════ Catalog ═════════════ --}}
            <section aria-label="{{ __('pos.catalog') }}">
                <div class="pos-toolbar">
                    <div class="pos-search">
                        <x-filament::icon icon="heroicon-o-magnifying-glass" />
                        <input x-ref="search" type="search" class="pos-input" autocomplete="off" autofocus
                               placeholder="{{ __('pos.search_placeholder') }}" aria-label="{{ __('pos.search_placeholder') }}"
                               wire:model.live.debounce.250ms="search" wire:keydown.enter.prevent="scan">
                    </div>
                    <button type="button" class="pos-btn" wire:click="openModal('bookings')" title="F6">
                        <x-filament::icon icon="heroicon-o-calendar-days" />
                        {{ __('pos.bookings.button') }}
                        @if ($this->todaysBookings->count())<span class="pos-badge">{{ $this->todaysBookings->count() }}</span>@endif
                    </button>
                    <span class="pos-pill-status" data-pos-sync-status hidden></span>
                    <button type="button" class="pos-icon-btn" wire:click="switchUser" title="{{ __('pos.switch.title') }}" aria-label="{{ __('pos.switch.title') }}">
                        <x-filament::icon icon="heroicon-o-arrows-right-left" />
                    </button>
                    <button type="button" class="pos-icon-btn" x-on:click="toggleTheme()" title="{{ __('pos.theme') }}" aria-label="{{ __('pos.theme') }}">
                        <x-filament::icon icon="heroicon-o-moon" class="dark:hidden" />
                        <x-filament::icon icon="heroicon-o-sun" class="hidden dark:block" />
                    </button>
                </div>

                <div class="pos-chips" role="tablist">
                    @foreach ($this->tabs as $key => $label)
                        <button type="button" role="tab" aria-selected="{{ $tab === $key ? 'true' : 'false' }}"
                                class="pos-chip {{ $tab === $key ? 'is-active' : '' }}" wire:click="setTab('{{ $key }}')">{{ $label }}</button>
                    @endforeach
                </div>

                @if ($this->categories->isNotEmpty())
                    <div class="pos-chips" style="margin-top: 8px">
                        <button type="button" class="pos-chip {{ $categoryId === null ? 'is-active' : '' }}" wire:click="$set('categoryId', null)">{{ __('pos.all') }}</button>
                        @foreach ($this->categories as $cat)
                            <button type="button" class="pos-chip {{ $categoryId === $cat->id ? 'is-active' : '' }}" wire:click="$set('categoryId', {{ $cat->id }})">{{ $cat->name }}</button>
                        @endforeach
                    </div>
                @endif

                {{-- Skeletons while the catalog changes --}}
                <div class="pos-tiles" wire:loading.delay.grid wire:target="setTab,search,categoryId">
                    @for ($i = 0; $i < 8; $i++)<div class="pos-skeleton"></div>@endfor
                </div>

                <div class="pos-tiles" wire:loading.remove wire:target="setTab,search,categoryId">
                    @forelse ($this->tiles as $tile)
                        <button type="button" class="pos-tile" wire:key="tile-{{ $tile['type'] }}-{{ $tile['id'] }}"
                                wire:click="addItem('{{ $tile['type'] }}', {{ $tile['id'] }})">
                            @if ($tile['stock'] !== null)
                                <span class="pos-tile-stock {{ $tile['stock'] <= 2 ? 'is-low' : '' }}">{{ $tile['stock'] }}</span>
                            @endif
                            <span class="pos-tile-name">@if ($tile['color'])<span class="pos-swatch" style="background: {{ $tile['color'] }}"></span>@endif{{ $tile['name'] }}</span>
                            <span>
                                @if ($tile['meta'])<span class="pos-tile-meta">{{ $tile['meta'] }}</span><br>@endif
                                <span class="pos-tile-price">{{ money($tile['price'], $cur) }}</span>
                            </span>
                        </button>
                    @empty
                        <div class="pos-empty" style="grid-column: 1 / -1">
                            <x-filament::icon icon="heroicon-o-sparkles" />
                            <p>{{ trim($search) !== '' ? __('pos.empty.search', ['term' => $search]) : __('pos.empty.catalog') }}</p>
                        </div>
                    @endforelse
                    @if ($tab === 'gift_cards')
                        <button type="button" class="pos-tile" wire:click="openModal('gift_card')">
                            <span class="pos-tile-name">{{ __('pos.gift.custom') }}</span>
                            <span class="pos-tile-price">{{ __('pos.gift.any_amount') }}</span>
                        </button>
                    @endif
                </div>
            </section>

            {{-- ═════════════ Ticket ═════════════ --}}
            <aside class="pos-card pos-ticket" aria-label="{{ __('pos.ticket.title') }}">
                <div class="pos-ticket-section">
                    @if ($client)
                        <div class="pos-client">
                            <div class="pos-avatar">{{ mb_substr($client->name, 0, 1) }}</div>
                            <div style="flex: 1; min-width: 0">
                                <div class="pos-line-name">{{ $client->name }} @if ($client->is_vip)<span class="pos-tag">VIP</span>@endif</div>
                                <div class="pos-muted" dir="ltr" style="text-align: start">{{ $client->phone }}</div>
                                <div class="pos-muted">
                                    {{ __('pos.client.wallet') }} {{ money((int) $client->wallet_balance, $cur) }} ·
                                    {{ __('pos.client.points', ['points' => $client->loyalty_points, 'value' => money(app(\App\Domain\Loyalty\Actions\LoyaltyLedger::class)->valueOf((int) $client->loyalty_points), $cur)]) }}
                                </div>
                            </div>
                            @unless ($bookingId)
                                <button type="button" class="pos-icon-btn" wire:click="clearClient" aria-label="{{ __('pos.client.remove') }}"><x-filament::icon icon="heroicon-o-x-mark" /></button>
                            @endunless
                        </div>
                    @else
                        <button type="button" class="pos-btn" style="width: 100%" wire:click="openModal('client')" title="F7">
                            <x-filament::icon icon="heroicon-o-user-plus" /> {{ __('pos.client.add') }}
                        </button>
                    @endif
                    @if ($bookingId)
                        <div style="margin-top: 8px"><span class="pos-tag is-success">{{ __('pos.bookings.loaded') }}</span></div>
                    @endif
                </div>

                <div class="pos-ticket-section pos-ticket-lines">
                    @forelse ($lines as $uid => $line)
                        @php($calc = $totals['lines'][$uid] ?? null)
                        <div class="pos-line" wire:key="line-{{ $uid }}">
                            <div>
                                <div class="pos-line-name">{{ $line['name'] }}</div>
                                <div class="pos-muted">
                                    {{ money((int) $line['unit_price'], $cur) }}
                                    @if ($line['discount_type'])
                                        <span class="pos-tag">−{{ $line['discount_type'] === 'percent' ? ($line['discount_value'] / 100).'%' : money((int) $line['discount_value'], $cur) }}</span>
                                    @endif
                                </div>
                            </div>
                            <div style="text-align: end; font-weight: 700">{{ money((int) ($calc['total'] ?? 0), $cur) }}</div>
                            <div class="pos-line-controls">
                                @if ($line['type'] !== 'gift_card')
                                    <div class="pos-stepper">
                                        <button type="button" wire:click="decrement('{{ $uid }}')" aria-label="−">−</button>
                                        <span>{{ $line['qty'] }}</span>
                                        <button type="button" wire:click="increment('{{ $uid }}')" aria-label="+">+</button>
                                    </div>
                                @endif
                                <select class="pos-input" wire:model.live="lines.{{ $uid }}.staff_id" aria-label="{{ __('pos.ticket.staff') }}">
                                    <option value="">{{ __('pos.ticket.staff') }}</option>
                                    @foreach ($staffList as $member)
                                        <option value="{{ $member->id }}">{{ $member->name }}</option>
                                    @endforeach
                                </select>
                                @if ($line['type'] !== 'gift_card')
                                    <button type="button" class="pos-icon-btn" wire:click="openDiscount('{{ $uid }}')" aria-label="{{ __('pos.ticket.discount') }}"><x-filament::icon icon="heroicon-o-receipt-percent" /></button>
                                @endif
                                @unless ($line['locked'])
                                    <button type="button" class="pos-icon-btn" wire:click="removeLine('{{ $uid }}')" aria-label="{{ __('pos.ticket.remove') }}"><x-filament::icon icon="heroicon-o-trash" /></button>
                                @endunless
                            </div>
                        </div>
                    @empty
                        <div class="pos-empty">
                            <x-filament::icon icon="heroicon-o-shopping-bag" />
                            <p>{{ __('pos.ticket.empty') }}</p>
                            @if ($this->todaysBookings->count())
                                <button type="button" class="pos-link" wire:click="openModal('bookings')">{{ __('pos.bookings.open_one', ['count' => $this->todaysBookings->count()]) }}</button>
                            @endif
                        </div>
                    @endforelse
                </div>

                @if ($lines)
                    <div class="pos-ticket-section">
                        <div class="pos-row"><span>{{ __('pos.ticket.subtotal') }}</span><span>{{ money($totals['subtotal'], $cur) }}</span></div>
                        <div class="pos-row">
                            <button type="button" class="pos-link" wire:click="openDiscount">{{ __('pos.ticket.discount') }} @unless ($canDiscount)<x-filament::icon icon="heroicon-m-lock-closed" class="inline h-3 w-3" />@endunless</button>
                            <span>{{ $totals['discount'] ? '−'.money($totals['discount'], $cur) : '—' }}</span>
                        </div>
                        @if ($totals['tax'] > 0)
                            <div class="pos-row pos-muted">
                                <span>{{ $branch?->tax_inclusive ? __('pos.ticket.tax_included', ['rate' => rtrim(rtrim(number_format((float) $branch->tax_percent, 2), '0'), '.')]) : __('pos.ticket.tax', ['rate' => rtrim(rtrim(number_format((float) $branch?->tax_percent, 2), '0'), '.')]) }}</span>
                                <span>{{ money($totals['tax'], $cur) }}</span>
                            </div>
                        @endif
                        <div class="pos-row">
                            <button type="button" class="pos-link" wire:click="openModal('tips')">{{ __('pos.ticket.tips') }}</button>
                            <span>{{ $totals['tips'] ? money($totals['tips'], $cur) : '—' }}</span>
                        </div>
                        @if ($totals['deposit'] > 0)
                            <div class="pos-row" style="color: #4f7a5a"><span>{{ __('pos.pay.deposit_paid') }}</span><span>−{{ money($totals['deposit'], $cur) }}</span></div>
                        @endif
                        <div class="pos-total"><span class="pos-eyebrow">{{ __('pos.ticket.total') }}</span><strong>{{ money($totals['total'], $cur) }}</strong></div>
                    </div>

                    {{-- Payment --}}
                    <div class="pos-ticket-section pos-stack">
                        @foreach ($tenders as $i => $tender)
                            <div class="pos-tender" wire:key="tender-{{ $i }}">
                                <span>{{ $tender['label'] }} @if (! empty($tender['reference']))<span class="pos-muted" dir="ltr">· {{ $tender['reference'] }}</span>@endif</span>
                                <span style="display: flex; align-items: center; gap: 6px">
                                    <strong>{{ money((int) $tender['amount'], $cur) }}</strong>
                                    @if ($tender['method'] !== 'online')
                                        <button type="button" class="pos-icon-btn" style="width: 36px; min-height: 36px" wire:click="removeTender({{ $i }})" aria-label="{{ __('pos.ticket.remove') }}"><x-filament::icon icon="heroicon-o-x-mark" /></button>
                                    @endif
                                </span>
                            </div>
                        @endforeach

                        @if ($onlinePaymentId && $this->onlinePayment)
                            <div class="pos-qr" wire:poll.3s="checkOnlinePayment">
                                <img src="{{ $this->onlineQr() }}" alt="QR">
                                <strong>{{ money((int) $this->onlinePayment->amount, $cur) }}</strong>
                                <span class="pos-muted">{{ __('pos.pay.scan_to_pay') }}</span>
                                <button type="button" class="pos-link" wire:click="cancelOnlinePayment">{{ __('pos.pay.hide_qr') }}</button>
                            </div>
                        @endif

                        @if ($totals['remaining'] > 0)
                            <div class="pos-due">{{ __('pos.pay.remaining') }} · {{ money($totals['remaining'], $cur) }}</div>
                            <div class="pos-two">
                                <input class="pos-input" inputmode="decimal" placeholder="{{ __('pos.pay.amount', ['currency' => $cur]) }}" wire:model="tenderAmount" aria-label="{{ __('pos.pay.amount', ['currency' => $cur]) }}">
                                <input class="pos-input" placeholder="{{ __('pos.pay.card_reference') }}" wire:model="tenderReference" aria-label="{{ __('pos.pay.card_reference') }}">
                            </div>
                            <div class="pos-chips">
                                @foreach ($this->quickCash as $amount)
                                    <button type="button" class="pos-chip" wire:click="tenderCash({{ $amount }})">{{ money($amount, $cur) }}</button>
                                @endforeach
                            </div>
                            <div class="pos-methods">
                                <button type="button" class="pos-method" wire:click="tenderCash" title="F4"><x-filament::icon icon="heroicon-o-banknotes" />{{ __('salon.payments.methods.cash') }}</button>
                                <button type="button" class="pos-method" wire:click="tenderCard"><x-filament::icon icon="heroicon-o-credit-card" />{{ __('salon.payments.methods.card_terminal') }}</button>
                                <button type="button" class="pos-method" wire:click="startOnlinePayment" wire:loading.attr="disabled" wire:target="startOnlinePayment"><x-filament::icon icon="heroicon-o-qr-code" />{{ __('pos.pay.online_qr') }}</button>
                                <button type="button" class="pos-method" wire:click="tenderWallet" @disabled(! $client)><x-filament::icon icon="heroicon-o-wallet" />{{ __('salon.payments.methods.wallet') }}</button>
                                <button type="button" class="pos-method" x-on:click="$refs.gift.classList.toggle('hidden'); $nextTick(() => $refs.giftInput.focus())"><x-filament::icon icon="heroicon-o-gift" />{{ __('salon.payments.methods.gift_card') }}</button>
                                <button type="button" class="pos-method" wire:click="tenderPoints" @disabled(! $client || ! $client->loyalty_points)><x-filament::icon icon="heroicon-o-star" />{{ __('salon.payments.methods.points') }}</button>
                            </div>
                            <div x-ref="gift" class="pos-two hidden">
                                <input x-ref="giftInput" class="pos-input" placeholder="{{ __('pos.pay.gift_code') }}" wire:model="giftCardCode" wire:keydown.enter="tenderGiftCard" dir="ltr">
                                <button type="button" class="pos-btn" wire:click="tenderGiftCard">{{ __('pos.pay.apply') }}</button>
                            </div>
                        @elseif ($totals['change'] > 0)
                            <div class="pos-change">{{ __('pos.pay.change') }} · {{ money($totals['change'], $cur) }}</div>
                        @endif

                        <button type="button" class="pos-primary" wire:click="complete" wire:loading.attr="disabled" wire:target="complete" @disabled($totals['remaining'] > 0) title="F8">
                            <span wire:loading.remove wire:target="complete">{{ __('pos.complete') }} · {{ money($totals['total'], $cur) }}</span>
                            <span wire:loading wire:target="complete">{{ __('pos.completing') }}</span>
                        </button>
                        <div class="pos-row">
                            <button type="button" class="pos-link" wire:click="openModal('notes')">{{ __('pos.ticket.notes') }}</button>
                            <button type="button" class="pos-link" style="color: #a4473f" wire:click="newTicket" wire:confirm="{{ __('pos.ticket.clear_confirm') }}">{{ __('pos.ticket.clear') }}</button>
                        </div>
                    </div>
                @endif
            </aside>
        </div>
    @endif

    {{-- ═════════════ Modals ═════════════ --}}
    @if ($modal)
        <div class="pos-modal-backdrop" wire:click.self="closeModal" role="dialog" aria-modal="true">
            @switch($modal)
                @case('client')
                    <div class="pos-modal">
                        <div class="pos-modal-head"><h2>{{ __('pos.client.title') }}</h2><button type="button" class="pos-icon-btn" wire:click="closeModal" aria-label="{{ __('salon.ui.cancel') }}"><x-filament::icon icon="heroicon-o-x-mark" /></button></div>
                        <div class="pos-stack">
                            <input class="pos-input" autofocus placeholder="{{ __('pos.client.search') }}" wire:model.live.debounce.250ms="clientSearch">
                            <div wire:loading.delay wire:target="clientSearch" class="pos-stack">
                                <div class="pos-skeleton" style="min-height: 56px"></div><div class="pos-skeleton" style="min-height: 56px"></div>
                            </div>
                            <div wire:loading.remove wire:target="clientSearch" class="pos-stack">
                                @foreach ($this->clientResults as $result)
                                    <button type="button" class="pos-booking" style="grid-template-columns: auto 1fr" wire:click="selectClient({{ $result->id }})" wire:key="client-{{ $result->id }}">
                                        <span class="pos-avatar">{{ mb_substr($result->name, 0, 1) }}</span>
                                        <span><strong>{{ $result->name }}</strong><br><span class="pos-muted" dir="ltr">{{ $result->phone }}</span></span>
                                    </button>
                                @endforeach
                                @if (mb_strlen(trim($clientSearch)) >= 2 && $this->clientResults->isEmpty())
                                    <p class="pos-muted">{{ __('pos.client.not_found') }}</p>
                                @endif
                                @if (strlen(preg_replace('/\D/', '', $clientSearch)) >= 8)
                                    <div class="pos-card pos-stack">
                                        <span class="pos-eyebrow">{{ __('pos.client.quick_create') }}</span>
                                        <input class="pos-input" placeholder="{{ __('pos.client.name') }}" wire:model="newClientName" wire:keydown.enter="quickCreateClient">
                                        <button type="button" class="pos-btn pos-gold" wire:click="quickCreateClient">{{ __('pos.client.create', ['phone' => $clientSearch]) }}</button>
                                    </div>
                                @endif
                            </div>
                        </div>
                    </div>
                    @break

                @case('bookings')
                    <div class="pos-modal pos-modal-wide">
                        <div class="pos-modal-head"><h2>{{ __('pos.bookings.title') }}</h2><button type="button" class="pos-icon-btn" wire:click="closeModal" aria-label="{{ __('salon.ui.cancel') }}"><x-filament::icon icon="heroicon-o-x-mark" /></button></div>
                        <div class="pos-stack">
                            @forelse ($this->todaysBookings as $booking)
                                <button type="button" class="pos-booking" wire:click="loadBooking({{ $booking->id }})" wire:key="booking-{{ $booking->id }}">
                                    <span class="pos-time" dir="ltr">{{ $booking->localStart()->format('H:i') }}</span>
                                    <span>
                                        <strong>{{ $booking->client?->name ?? __('pos.client.walk_in') }}</strong>
                                        <span class="pos-tag {{ $booking->status === \App\Domain\Booking\Enums\BookingStatus::CheckedIn ? 'is-success' : '' }}">{{ $booking->status->getLabel() }}</span><br>
                                        <span class="pos-muted">{{ $booking->items->map(fn ($i) => $i->service?->name.($i->staff ? ' · '.$i->staff->name : ''))->implode(', ') }}</span>
                                    </span>
                                    <span style="text-align: end">
                                        <strong>{{ money((int) $booking->total, $cur) }}</strong>
                                        @if ($booking->amount_paid > 0)<br><span class="pos-tag is-success">{{ __('pos.bookings.paid', ['amount' => money((int) $booking->amount_paid, $cur)]) }}</span>@endif
                                    </span>
                                </button>
                            @empty
                                <div class="pos-empty"><x-filament::icon icon="heroicon-o-calendar" /><p>{{ __('pos.bookings.empty') }}</p></div>
                            @endforelse
                        </div>
                    </div>
                    @break

                @case('discount')
                    <div class="pos-modal">
                        <div class="pos-modal-head"><h2>{{ $discountTarget === 'ticket' ? __('pos.discount.ticket') : __('pos.discount.line') }}</h2><button type="button" class="pos-icon-btn" wire:click="closeModal" aria-label="{{ __('salon.ui.cancel') }}"><x-filament::icon icon="heroicon-o-x-mark" /></button></div>
                        <div class="pos-stack">
                            <div class="pos-chips">
                                <button type="button" class="pos-chip {{ $discountType === 'percent' ? 'is-active' : '' }}" wire:click="$set('discountType', 'percent')">%</button>
                                <button type="button" class="pos-chip {{ $discountType === 'fixed' ? 'is-active' : '' }}" wire:click="$set('discountType', 'fixed')">{{ $cur }}</button>
                                @foreach ([5, 10, 15, 20] as $p)
                                    <button type="button" class="pos-chip" wire:click="$set('discountType', 'percent'); $set('discountInput', '{{ $p }}')">{{ $p }}%</button>
                                @endforeach
                            </div>
                            <input class="pos-input" style="font-size: 1.4rem; text-align: center" inputmode="decimal" autofocus wire:model="discountInput" wire:keydown.enter="applyDiscount">
                            @unless ($canDiscount || $discountApprovedBy)
                                <p class="pos-muted"><x-filament::icon icon="heroicon-m-lock-closed" class="inline h-4 w-4" /> {{ __('pos.discount.needs_pin') }}</p>
                            @endunless
                            <button type="button" class="pos-primary" wire:click="applyDiscount">{{ __('pos.discount.apply') }}</button>
                        </div>
                    </div>
                    @break

                @case('pin')
                    <div class="pos-modal" style="width: min(380px, 100%)" x-data="{ pin: $wire.entangle('pin') }">
                        <div class="pos-modal-head"><h2>{{ $pinPurpose === 'switch' ? __('pos.switch.title') : __('pos.pin.title') }}</h2><button type="button" class="pos-icon-btn" wire:click="closeModal" aria-label="{{ __('salon.ui.cancel') }}"><x-filament::icon icon="heroicon-o-x-mark" /></button></div>
                        <p class="pos-muted" style="text-align: center">{{ $pinPurpose === 'switch' ? __('pos.switch.body') : __('pos.pin.body') }}</p>
                        <div class="pos-pin-dots" aria-hidden="true">
                            <template x-for="i in 6"><span :class="{ 'is-filled': (pin || '').length >= i }"></span></template>
                        </div>
                        <input type="password" inputmode="numeric" autocomplete="off" class="pos-input" style="text-align: center; letter-spacing: .5em" maxlength="6" x-model="pin" wire:keydown.enter="submitPin" autofocus aria-label="PIN">
                        <div class="pos-keypad" style="margin-top: 12px" dir="ltr">
                            @foreach ([1, 2, 3, 4, 5, 6, 7, 8, 9] as $digit)
                                <button type="button" x-on:click="pin = ((pin || '') + '{{ $digit }}').slice(0, 6)">{{ $digit }}</button>
                            @endforeach
                            <button type="button" x-on:click="pin = (pin || '').slice(0, -1)">⌫</button>
                            <button type="button" x-on:click="pin = ((pin || '') + '0').slice(0, 6)">0</button>
                            <button type="button" class="pos-gold" wire:click="submitPin">✓</button>
                        </div>
                    </div>
                    @break

                @case('tips')
                    <div class="pos-modal">
                        <div class="pos-modal-head"><h2>{{ __('pos.tips.title') }}</h2><button type="button" class="pos-icon-btn" wire:click="closeModal" aria-label="{{ __('salon.ui.cancel') }}"><x-filament::icon icon="heroicon-o-x-mark" /></button></div>
                        <div class="pos-stack">
                            @php($ticketStaff = collect($lines)->pluck('staff_id')->filter()->map(fn ($id) => (int) $id)->unique())
                            @foreach ($staffList->sortByDesc(fn ($s) => $ticketStaff->contains($s->id)) as $member)
                                <label class="pos-row" wire:key="tip-{{ $member->id }}">
                                    <span>{{ $member->name }} @if ($ticketStaff->contains($member->id))<span class="pos-tag">{{ __('pos.tips.on_ticket') }}</span>@endif</span>
                                    <input class="pos-input" style="max-width: 140px; text-align: end" inputmode="decimal" placeholder="0.00"
                                           value="{{ $tips[$member->id] ?? '' }}" wire:change="setTip({{ $member->id }}, $event.target.value)">
                                </label>
                            @endforeach
                            <button type="button" class="pos-primary" wire:click="closeModal">{{ __('pos.done') }}</button>
                        </div>
                    </div>
                    @break

                @case('gift_card')
                    <div class="pos-modal">
                        <div class="pos-modal-head"><h2>{{ __('pos.gift.title') }}</h2><button type="button" class="pos-icon-btn" wire:click="closeModal" aria-label="{{ __('salon.ui.cancel') }}"><x-filament::icon icon="heroicon-o-x-mark" /></button></div>
                        <div class="pos-stack">
                            <label class="pos-muted">{{ __('pos.gift.amount', ['currency' => $cur]) }}<input class="pos-input" inputmode="decimal" style="font-size: 1.4rem; text-align: center" wire:model="giftAmount" autofocus></label>
                            <label class="pos-muted">{{ __('pos.gift.recipient') }}<input class="pos-input" wire:model="giftRecipient"></label>
                            <label class="pos-muted">{{ __('pos.gift.recipient_phone') }}<input class="pos-input" dir="ltr" inputmode="tel" wire:model="giftRecipientPhone"></label>
                            <button type="button" class="pos-primary" wire:click="addGiftCard">{{ __('pos.gift.add') }}</button>
                        </div>
                    </div>
                    @break

                @case('notes')
                    <div class="pos-modal">
                        <div class="pos-modal-head"><h2>{{ __('pos.ticket.notes') }}</h2><button type="button" class="pos-icon-btn" wire:click="closeModal" aria-label="{{ __('salon.ui.cancel') }}"><x-filament::icon icon="heroicon-o-x-mark" /></button></div>
                        <div class="pos-stack">
                            <textarea class="pos-input" style="min-height: 120px; padding-top: 12px" wire:model="notes"></textarea>
                            <button type="button" class="pos-primary" wire:click="closeModal">{{ __('pos.done') }}</button>
                        </div>
                    </div>
                    @break

                @case('receipt')
                    @php($sale = $this->lastSale)
                    <div class="pos-modal">
                        @if ($sale)
                            <div style="text-align: center">
                                <x-filament::icon icon="heroicon-o-check-badge" class="mx-auto h-12 w-12" style="color: #4f7a5a" />
                                <p class="pos-eyebrow" style="margin-top: 8px">{{ __('pos.receipt.done') }} · <span dir="ltr">{{ $sale->number }}</span></p>
                                <p class="pos-display" style="font-size: 2.4rem; margin: 4px 0">{{ money((int) $sale->total, $cur) }}</p>
                                @if ($sale->change_due > 0)<div class="pos-change" style="margin: 8px 0">{{ __('pos.pay.change') }} · {{ money((int) $sale->change_due, $cur) }}</div>@endif
                            </div>
                            <div class="pos-stack" style="margin-top: 16px">
                                <div class="pos-two">
                                    <a class="pos-btn" href="{{ route('pos.receipt.print', ['sale' => $sale->id]) }}" target="_blank" rel="noopener"><x-filament::icon icon="heroicon-o-printer" />{{ __('pos.receipt.print') }}</a>
                                    <a class="pos-btn" href="{{ route('pos.receipt.pdf', ['sale' => $sale->id]) }}" target="_blank" rel="noopener"><x-filament::icon icon="heroicon-o-document-arrow-down" />PDF</a>
                                </div>
                                @if ($sale->client?->phone)
                                    <div class="pos-two">
                                        <a class="pos-btn" href="{{ $this->whatsappLink() }}" target="_blank" rel="noopener"><x-filament::icon icon="heroicon-o-chat-bubble-left-right" />{{ __('pos.receipt.whatsapp_link') }}</a>
                                        <button type="button" class="pos-btn" wire:click="sendReceipt('whatsapp')">{{ __('pos.receipt.whatsapp_send') }}</button>
                                    </div>
                                @endif
                                <div class="pos-two">
                                    <input class="pos-input" type="email" dir="ltr" placeholder="email@example.com" wire:model="receiptEmail">
                                    <button type="button" class="pos-btn" wire:click="sendReceipt('email')"><x-filament::icon icon="heroicon-o-envelope" />{{ __('pos.receipt.email') }}</button>
                                </div>
                                <button type="button" class="pos-primary" wire:click="newTicket" autofocus>{{ __('pos.new_ticket') }}</button>
                            </div>
                        @endif
                    </div>
                    @break
            @endswitch
        </div>
    @endif

    {{-- ═════════════ Offline mode (driven by resources/js/pos-offline.js) ═════════════ --}}
    <div wire:ignore data-pos-offline="{{ json_encode([
            'swUrl' => route('pos.sw'),
            'syncUrl' => route('pos.sync'),
            'catalogUrl' => route('pos.catalog', ['branch' => $branch?->id]),
            'csrf' => csrf_token(),
            'branchId' => $branch?->id,
            'registerId' => $registerId,
            'shiftId' => $shift?->id,
            'currency' => $cur,
            'locale' => app()->getLocale(),
            'taxPercent' => (float) ($branch?->tax_percent ?? 0),
            'taxInclusive' => (bool) ($branch?->tax_inclusive ?? true),
            'i18n' => trans('pos.offline'),
        ]) }}">
        <div class="pos-offline-overlay" data-offline-overlay hidden>
            <div class="pos-offline-banner">
                <span><strong>{{ __('pos.offline.title') }}</strong> · {{ __('pos.offline.body') }}</span>
                <span class="pos-pill-status" data-pos-sync-status></span>
            </div>
            <div data-offline-panel></div>
        </div>
        <div class="pos-offline-toast" data-offline-toast hidden role="status"></div>
    </div>
</div>

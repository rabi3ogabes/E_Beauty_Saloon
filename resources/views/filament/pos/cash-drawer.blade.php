@php
    $shift = $this->shift;
    $summary = $this->summary;
@endphp
<x-filament-panels::page>
    <div class="pos-shell pos-stack">
        @if ($this->registers->count() > 1)
            <div class="pos-chips">
                @foreach ($this->registers as $register)
                    <button type="button" class="pos-chip {{ $register->id === $registerId ? 'is-active' : '' }}" wire:click="selectRegister({{ $register->id }})">{{ $register->branch?->name }} · {{ $register->name }}</button>
                @endforeach
            </div>
        @endif

        @if (! $shift)
            <div class="pos-card pos-empty">
                <x-filament::icon icon="heroicon-o-lock-closed" />
                <p>{{ __('pos.shift.no_open') }}</p>
                <a class="pos-btn pos-gold" href="{{ \App\Filament\Pos\Pages\Terminal::getUrl() }}">{{ __('pos.shift.open') }}</a>
            </div>
        @else
            <div class="pos-card">
                <p class="pos-eyebrow">{{ __('pos.shift.x_report') }} · #{{ $shift->id }}</p>
                <p class="pos-muted">{{ __('pos.shift.opened_at') }} {{ $shift->opened_at->timezone(tenant()->timezone ?? config('app.timezone'))->format('H:i') }} · {{ $shift->user?->name }}</p>
                <div class="grid gap-3 sm:grid-cols-2 lg:grid-cols-4" style="margin-top: 12px">
                    @foreach (['opening_float', 'cash_sales', 'cash_refunds', 'cash_out', 'cash_in'] as $key)
                        <div class="pos-tender" style="flex-direction: column; align-items: flex-start">
                            <span class="pos-muted">{{ __('pos.shift.'.$key) }}</span>
                            <strong style="font-size: 1.15rem">{{ money($summary[$key]) }}</strong>
                        </div>
                    @endforeach
                    <div class="pos-due" style="text-align: start">
                        <span class="pos-muted">{{ __('pos.shift.expected') }}</span><br>
                        <strong class="pos-display" style="font-size: 1.6rem">{{ money($summary['expected_cash']) }}</strong>
                    </div>
                </div>
                <div class="grid gap-3 sm:grid-cols-3" style="margin-top: 12px">
                    <div class="pos-row"><span class="pos-muted">{{ __('pos.shift.sales_count') }}</span><strong>{{ $summary['sales_count'] }}</strong></div>
                    <div class="pos-row"><span class="pos-muted">{{ __('pos.shift.net_sales') }}</span><strong>{{ money($summary['net_sales']) }}</strong></div>
                    <div class="pos-row"><span class="pos-muted">{{ __('pos.shift.refunds') }}</span><strong>{{ money($summary['refunds_total']) }}</strong></div>
                </div>
                @if ($summary['by_method'])
                    <div class="pos-chips" style="margin-top: 12px">
                        @foreach ($summary['by_method'] as $method => $row)
                            <span class="pos-tag">{{ $method === 'deposit' ? __('pos.pay.deposit_paid') : __('salon.payments.methods.'.$method) }} · {{ money($row['total']) }}</span>
                        @endforeach
                    </div>
                @endif
            </div>

            <div class="pos-card">
                <h3 class="pos-h3">{{ __('pos.shift.movements') }}</h3>
                @forelse ($shift->cashMovements as $movement)
                    <div class="pos-row" wire:key="mv-{{ $movement->id }}">
                        <span>{{ $movement->created_at->timezone(tenant()->timezone ?? config('app.timezone'))->format('H:i') }} · {{ __('pos.shift.movement_'.$movement->type) }} · {{ $movement->reason }} <span class="pos-muted">{{ $movement->user?->name }}</span></span>
                        <strong style="color: {{ $movement->type === 'out' ? '#a4473f' : '#4f7a5a' }}">{{ $movement->type === 'out' ? '−' : '+' }}{{ money((int) $movement->amount) }}</strong>
                    </div>
                @empty
                    <p class="pos-muted">—</p>
                @endforelse
            </div>
        @endif

        @if ($this->recentShifts->isNotEmpty())
            <div class="pos-card">
                <h3 class="pos-h3">{{ __('pos.nav.shifts') }}</h3>
                @foreach ($this->recentShifts as $closed)
                    <div class="pos-row" wire:key="shift-{{ $closed->id }}">
                        <span>#{{ $closed->id }} · {{ $closed->closed_at?->timezone(tenant()->timezone ?? config('app.timezone'))->format('d M H:i') }} · {{ $closed->closer?->name }}</span>
                        <span style="display: flex; gap: 12px; align-items: center">
                            <span style="color: {{ ($closed->variance ?? 0) < 0 ? '#a4473f' : '#4f7a5a' }}">{{ __('pos.shift.variance') }} {{ money((int) $closed->variance) }}</span>
                            <a class="pos-link" href="{{ route('pos.shift.z-report', $closed->id) }}" target="_blank" rel="noopener">{{ __('pos.shift.z_report') }}</a>
                        </span>
                    </div>
                @endforeach
            </div>
        @endif
    </div>
</x-filament-panels::page>

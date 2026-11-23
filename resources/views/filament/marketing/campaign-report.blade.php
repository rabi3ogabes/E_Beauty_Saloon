<x-filament-panels::page>
    @php
        $funnel = collect(['recipients', 'sent', 'delivered', 'read', 'clicked', 'booked'])->map(fn ($k) => ['key' => $k, 'value' => (int) $report[$k]]);
        $max = max(1, $funnel->max('value'));
        $statusColor = match ($campaign->status) { 'sent' => 'success', 'sending', 'testing' => 'info', 'scheduled' => 'warning', 'failed', 'cancelled' => 'danger', default => 'gray' };
    @endphp

    <div class="flex flex-wrap items-center gap-3 text-sm text-gray-500 dark:text-gray-400">
        <x-filament::badge :color="$statusColor">{{ __('marketing.campaigns.statuses.'.$campaign->status) }}</x-filament::badge>
        <span>{{ $campaign->segment?->name }}</span>
        <span>·</span>
        <span>{{ collect($campaign->channels)->map(fn ($c) => __('marketing.channels.'.$c))->join(' → ') }}</span>
        @if ($campaign->started_at)<span>·</span><span>{{ $campaign->started_at->timezone(tenant()->timezone)->translatedFormat('j M Y, H:i') }}</span>@endif
        @if ($campaign->status === 'scheduled' && $campaign->scheduled_at)<span>·</span><span>{{ __('marketing.campaigns.scheduled_at') }}: {{ $campaign->scheduled_at->timezone(tenant()->timezone)->translatedFormat('j M Y, H:i') }}</span>@endif
    </div>

    {{-- KPIs --}}
    <div class="grid gap-4 sm:grid-cols-2 xl:grid-cols-4">
        <x-filament::section>
            <p class="text-xs uppercase tracking-wider text-gray-500">{{ __('marketing.campaigns.kpi.revenue') }}</p>
            <p class="mt-2 font-display text-3xl font-semibold text-gray-950 dark:text-white">{{ money($report['revenue']) }}</p>
            <p class="mt-1 text-sm text-gray-500">{{ trans_choice('marketing.segments.clients', $report['booked'], ['count' => $report['booked']]) }} · {{ __('marketing.campaigns.funnel.booked') }}</p>
        </x-filament::section>
        <x-filament::section>
            <p class="text-xs uppercase tracking-wider text-gray-500">{{ __('marketing.campaigns.funnel.clicked') }}</p>
            <p class="mt-2 font-display text-3xl font-semibold text-gray-950 dark:text-white">{{ number_format($report['clicked']) }}</p>
            <p class="mt-1 text-sm text-gray-500">{{ $report['sent'] ? round($report['clicked'] / $report['sent'] * 100, 1) : 0 }}% CTR</p>
        </x-filament::section>
        <x-filament::section>
            <p class="text-xs uppercase tracking-wider text-gray-500">{{ __('marketing.campaigns.kpi.cost') }}</p>
            <p class="mt-2 font-display text-3xl font-semibold text-gray-950 dark:text-white">{{ money($report['cost']) }}</p>
            <p class="mt-1 text-sm text-gray-500">{{ __('marketing.campaigns.kpi.roi') }}: {{ $report['roi'] !== null ? $report['roi'].'×' : '—' }}</p>
        </x-filament::section>
        <x-filament::section>
            <p class="text-xs uppercase tracking-wider text-gray-500">{{ __('marketing.campaigns.kpi.skipped') }}</p>
            <p class="mt-2 font-display text-3xl font-semibold text-gray-950 dark:text-white">{{ number_format($report['skipped_no_consent']) }}</p>
            <p class="mt-1 text-sm text-gray-500">{{ __('marketing.campaigns.kpi.deferred') }}: {{ $report['deferred'] }} · {{ __('marketing.campaigns.kpi.failed') }}: {{ $report['failed'] }}</p>
        </x-filament::section>
    </div>

    {{-- Funnel --}}
    <x-filament::section :heading="__('marketing.campaigns.report')">
        <div class="space-y-3">
            @foreach ($funnel as $step)
                <div class="grid grid-cols-[7rem_1fr_4rem] items-center gap-3 text-sm sm:grid-cols-[9rem_1fr_5rem]">
                    <span class="text-gray-600 dark:text-gray-300">{{ __('marketing.campaigns.funnel.'.$step['key']) }}</span>
                    <div class="h-7 overflow-hidden rounded-full bg-gray-100 dark:bg-white/5">
                        <div class="h-full rounded-full bg-gradient-to-r from-primary-400 to-primary-600 transition-all duration-500" style="width: {{ max(2, round($step['value'] / $max * 100)) }}%"></div>
                    </div>
                    <span class="text-end font-medium tabular-nums text-gray-950 dark:text-white">{{ number_format($step['value']) }}</span>
                </div>
            @endforeach
        </div>
        @if ($report['by_channel'])
            <div class="mt-5 flex flex-wrap gap-2 text-xs">
                <span class="text-gray-500">{{ __('marketing.campaigns.by_channel') }}:</span>
                @foreach ($report['by_channel'] as $channel => $count)
                    <x-filament::badge color="gray">{{ __('marketing.channels.'.$channel) }} · {{ number_format($count) }}</x-filament::badge>
                @endforeach
            </div>
        @endif
    </x-filament::section>

    @if ($report['variants'])
        <x-filament::section :heading="__('marketing.campaigns.variants')">
            <div class="grid gap-4 sm:grid-cols-2">
                @foreach ($report['variants'] as $v)
                    <div @class(['rounded-xl border p-4', 'border-primary-500 ring-1 ring-primary-500' => $v['is_winner'], 'border-gray-200 dark:border-white/10' => ! $v['is_winner']])>
                        <div class="flex items-center justify-between">
                            <span class="font-display text-xl">{{ $v['key'] }}</span>
                            @if ($v['is_winner'])<x-filament::badge color="success">{{ __('marketing.campaigns.winner') }}</x-filament::badge>@endif
                        </div>
                        <dl class="mt-3 grid grid-cols-3 gap-2 text-sm">
                            <div><dt class="text-gray-500">{{ __('marketing.campaigns.funnel.sent') }}</dt><dd class="font-medium">{{ $v['sent'] }}</dd></div>
                            <div><dt class="text-gray-500">{{ __('marketing.campaigns.funnel.clicked') }}</dt><dd class="font-medium">{{ $v['clicked'] }}</dd></div>
                            <div><dt class="text-gray-500">{{ __('marketing.campaigns.funnel.booked') }}</dt><dd class="font-medium">{{ $v['booked'] }}</dd></div>
                        </dl>
                    </div>
                @endforeach
            </div>
        </x-filament::section>
    @endif

    <div class="grid gap-6 xl:grid-cols-2">
        <x-filament::section :heading="__('marketing.campaigns.attributed_bookings')">
            @forelse ($report['bookings'] as $b)
                <div class="flex items-center justify-between border-b border-gray-100 py-2 text-sm last:border-0 dark:border-white/5">
                    <span class="font-mono">{{ $b->reference }}</span>
                    <span class="text-gray-500">{{ $b->created_at->timezone(tenant()->timezone)->translatedFormat('j M, H:i') }}</span>
                    <x-filament::badge :color="$b->status->getColor()">{{ $b->status->getLabel() }}</x-filament::badge>
                    <span class="font-medium tabular-nums">{{ money($b->total) }}</span>
                </div>
            @empty
                <p class="text-sm text-gray-500">{{ __('marketing.campaigns.no_bookings') }}</p>
            @endforelse
        </x-filament::section>

        <x-filament::section :heading="__('marketing.campaigns.messages')">
            @foreach ($recent as $m)
                <div class="flex items-center justify-between gap-3 border-b border-gray-100 py-2 text-sm last:border-0 dark:border-white/5">
                    <span class="truncate">{{ $m->client?->name }}</span>
                    <span class="text-gray-500">{{ $m->channel ? __('marketing.channels.'.$m->channel) : '—' }}</span>
                    <x-filament::badge :color="match ($m->status) { 'clicked', 'read' => 'success', 'sent', 'delivered' => 'info', 'failed' => 'danger', 'skipped_no_consent', 'skipped_credits' => 'gray', default => 'warning' }">
                        {{ __('marketing.campaigns.message_statuses.'.$m->status) }}
                    </x-filament::badge>
                </div>
            @endforeach
        </x-filament::section>
    </div>
</x-filament-panels::page>

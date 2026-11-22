<x-filament-panels::page>
    <div class="grid gap-4 lg:grid-cols-[22rem_1fr]" wire:poll.15s>
        <x-filament::section compact>
            <div class="mb-3 flex gap-2">
                @foreach (['all', 'unread', 'handoff'] as $f)
                    <button type="button" wire:click="setFilter('{{ $f }}')" @class(['rounded-full px-3 py-1 text-xs transition', 'bg-primary-600 text-white' => $filter === $f, 'border border-gray-200 text-gray-600 dark:border-white/10 dark:text-gray-300' => $filter !== $f])>
                        {{ __('insights.assistant.filters.'.$f) }}
                    </button>
                @endforeach
            </div>
            @forelse ($this->conversations as $c)
                <button type="button" wire:key="conv-{{ $c->id }}" wire:click="open({{ $c->id }})" @class([
                    'flex w-full items-start gap-3 rounded-xl px-3 py-3 text-start transition duration-150',
                    'bg-primary-50 dark:bg-white/10' => $conversation === $c->id,
                    'hover:bg-gray-50 dark:hover:bg-white/5' => $conversation !== $c->id,
                ])>
                    <span class="flex h-9 w-9 shrink-0 items-center justify-center rounded-full text-sm font-semibold" style="background: #E9D6CF; color: #A88B4F">{{ mb_strtoupper(mb_substr($c->displayName(), 0, 1)) }}</span>
                    <span class="min-w-0 flex-1">
                        <span class="flex items-center justify-between gap-2">
                            <span class="truncate font-medium text-gray-950 dark:text-white">{{ $c->displayName() }}</span>
                            <span class="shrink-0 text-[11px] text-gray-400">{{ $c->last_message_at?->diffForHumans(short: true) }}</span>
                        </span>
                        <span class="mt-0.5 flex items-center gap-2">
                            <span class="truncate text-xs text-gray-500" dir="ltr">{{ $c->phone }}</span>
                            @if ($c->status === 'handoff')<x-filament::badge size="xs" color="warning">{{ __('insights.assistant.human') }}</x-filament::badge>@endif
                            @if ($c->unread_count)<x-filament::badge size="xs" color="primary">{{ $c->unread_count }}</x-filament::badge>@endif
                        </span>
                    </span>
                </button>
            @empty
                <div class="py-10 text-center text-sm text-gray-500">
                    <x-filament::icon icon="heroicon-o-chat-bubble-left-right" class="mx-auto mb-2 h-8 w-8 text-primary-500" />
                    {{ __('insights.assistant.empty') }}
                </div>
            @endforelse
        </x-filament::section>

        <x-filament::section compact>
            @if ($current = $this->current)
                <div class="flex flex-wrap items-center justify-between gap-2 border-b border-gray-100 pb-3 dark:border-white/10">
                    <div>
                        <p class="font-display text-lg font-semibold text-gray-950 dark:text-white">{{ $current->displayName() }}</p>
                        <p class="text-xs text-gray-500"><span dir="ltr">{{ $current->phone }}</span> · {{ strtoupper($current->locale) }}
                            @if ($current->lastBooking) · {{ __('insights.assistant.last_booking', ['ref' => $current->lastBooking->reference]) }}@endif
                        </p>
                    </div>
                    <div class="flex gap-2">
                        @if ($current->status === 'handoff')
                            <x-filament::badge color="warning">{{ __('insights.assistant.handoff_reasons.'.($current->handoff_reason ?? 'staff')) }}</x-filament::badge>
                            <x-filament::button size="sm" color="gray" class="rounded-full" wire:click="resumeAi">{{ __('insights.assistant.resume') }}</x-filament::button>
                        @else
                            <x-filament::button size="sm" color="gray" class="rounded-full" wire:click="takeOver">{{ __('insights.assistant.take_over') }}</x-filament::button>
                        @endif
                    </div>
                </div>

                <div class="max-h-[28rem] space-y-3 overflow-y-auto py-4">
                    @foreach ($current->messages as $m)
                        <div wire:key="msg-{{ $m->id }}" @class(['flex', 'justify-start' => $m->direction === 'in', 'justify-end' => $m->direction === 'out'])>
                            <div @class([
                                'max-w-[80%] rounded-2xl px-4 py-2 text-sm leading-relaxed',
                                'bg-gray-100 text-gray-900 dark:bg-white/10 dark:text-white' => $m->direction === 'in',
                                'bg-primary-600 text-white' => $m->author === 'staff',
                                'text-gray-900 dark:text-white' => $m->author === 'bot',
                            ]) @if ($m->author === 'bot') style="background: #F3E9D6" @endif dir="auto">
                                <p class="whitespace-pre-line">{{ $m->body }}</p>
                                <p class="mt-1 text-[10px] opacity-70">
                                    {{ $m->created_at->setTimezone(tenant()->timezone)->format('d M H:i') }} ·
                                    {{ $m->author === 'staff' ? ($m->user?->name ?? __('insights.assistant.staff')) : __('insights.assistant.authors.'.$m->author) }}
                                    @if ($m->tool_calls) · {{ collect($m->tool_calls)->pluck('name')->implode(', ') }}@endif
                                </p>
                            </div>
                        </div>
                    @endforeach
                </div>

                <form wire:submit="send" class="flex gap-2 border-t border-gray-100 pt-3 dark:border-white/10">
                    <textarea wire:model="reply" rows="2" maxlength="4000" placeholder="{{ __('insights.assistant.reply_placeholder') }}" class="min-h-11 flex-1 rounded-[10px] border border-gray-200 bg-white px-3 py-2 text-sm dark:border-white/10 dark:bg-white/5"></textarea>
                    <x-filament::button type="submit" icon="heroicon-m-paper-airplane" class="self-end rounded-full" wire:loading.attr="disabled" wire:target="send">{{ __('insights.assistant.send') }}</x-filament::button>
                </form>
                <p class="mt-1 text-[11px] text-gray-400">{{ __('insights.assistant.reply_hint') }}</p>
            @else
                <div class="py-16 text-center text-sm text-gray-500">{{ __('insights.assistant.pick') }}</div>
            @endif
        </x-filament::section>
    </div>
</x-filament-panels::page>

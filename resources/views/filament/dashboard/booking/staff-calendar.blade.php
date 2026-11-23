<x-filament-panels::page>
    @if (! $branch)
        <div class="rounded-2xl border border-dashed border-gray-300 p-10 text-center text-sm text-gray-500">{{ __('booking.calendar_page.no_branch') }}</div>
    @else
        @php
            $height = (int) round(($close - $open) * $pxPerMinute);
            $columns = $mode === 'week' ? $days->map(fn ($d) => ['key' => $d->toDateString(), 'staff' => null, 'date' => $d->toDateString(), 'label' => $d->translatedFormat('D j'), 'sub' => $d->translatedFormat('M'), 'color' => null, 'photo' => null])
                : $staff->map(fn ($s) => ['key' => 's'.$s->id, 'staff' => $s->id, 'date' => $date, 'label' => $s->name, 'sub' => $s->title, 'color' => $s->color, 'photo' => $s->photo_path ? asset('storage/'.$s->photo_path) : null]);
        @endphp
        <div wire:poll.20s.visible
             x-data="staffCalendar({ ppm: {{ $pxPerMinute }}, open: {{ $open }}, canManage: @js($canManage), channel: @js($channel) })"
             x-init="init()" class="space-y-4">

            {{-- Toolbar --}}
            <div class="flex flex-wrap items-center gap-2">
                <div class="flex items-center gap-1 rounded-full border border-gray-200 bg-white p-1 dark:border-white/10 dark:bg-white/5">
                    <button type="button" wire:click="shift(-1)" class="rounded-full px-3 py-1.5 text-sm hover:bg-gray-100 dark:hover:bg-white/10" aria-label="{{ __('booking.calendar_page.previous') }}"><span class="inline-block rtl:rotate-180">‹</span></button>
                    <button type="button" wire:click="today" class="rounded-full px-3 py-1.5 text-sm font-medium hover:bg-gray-100 dark:hover:bg-white/10">{{ __('booking.calendar_page.today') }}</button>
                    <button type="button" wire:click="shift(1)" class="rounded-full px-3 py-1.5 text-sm hover:bg-gray-100 dark:hover:bg-white/10" aria-label="{{ __('booking.calendar_page.next') }}"><span class="inline-block rtl:rotate-180">›</span></button>
                </div>
                <input type="date" wire:model.live="date" class="rounded-full border border-gray-200 bg-white px-3 py-1.5 text-sm dark:border-white/10 dark:bg-white/5" aria-label="{{ __('booking.calendar_page.date') }}">
                <p class="px-2 font-medium">{{ \Carbon\CarbonImmutable::parse($date, $tz)->translatedFormat($mode === 'week' ? 'F Y' : 'l j F') }}</p>

                <div class="ms-auto flex flex-wrap items-center gap-2">
                    @if ($branches->count() > 1)
                        <select wire:model.live="branchId" class="rounded-full border border-gray-200 bg-white px-3 py-1.5 text-sm dark:border-white/10 dark:bg-white/5" aria-label="{{ __('salon.ui.branch') }}">
                            @foreach ($branches as $b)<option value="{{ $b->id }}">{{ $b->name }}</option>@endforeach
                        </select>
                    @endif
                    <div class="flex rounded-full border border-gray-200 bg-white p-1 text-sm dark:border-white/10 dark:bg-white/5" role="group" aria-label="{{ __('booking.calendar_page.view') }}">
                        @foreach (['day', 'week'] as $m)
                            <button type="button" wire:click="setMode('{{ $m }}')" @class(['rounded-full px-3 py-1', 'bg-gray-900 text-white dark:bg-primary-500 dark:text-gray-950' => $mode === $m]) aria-pressed="{{ $mode === $m ? 'true' : 'false' }}">{{ __('booking.calendar_page.modes.'.$m) }}</button>
                        @endforeach
                    </div>
                    <div class="flex rounded-full border border-gray-200 bg-white p-1 text-sm dark:border-white/10 dark:bg-white/5" role="group" aria-label="{{ __('booking.calendar_page.color_by') }}">
                        @foreach (['staff', 'status'] as $c)
                            <button type="button" wire:click="$set('colorBy', '{{ $c }}')" @class(['rounded-full px-3 py-1', 'bg-gray-900 text-white dark:bg-primary-500 dark:text-gray-950' => $colorBy === $c])>{{ __('booking.calendar_page.color.'.$c) }}</button>
                        @endforeach
                    </div>
                </div>
            </div>

            @if ($columns->isEmpty())
                <div class="rounded-2xl border border-dashed border-gray-300 p-10 text-center text-sm text-gray-500">{{ __('booking.calendar_page.no_staff') }}</div>
            @else
                {{-- Grid --}}
                <div class="relative overflow-auto rounded-2xl border border-gray-200 bg-white shadow-sm dark:border-white/10 dark:bg-gray-900" style="max-height: 75vh">
                    <div class="grid min-w-max" style="grid-template-columns: 56px repeat({{ $columns->count() }}, minmax({{ $mode === 'week' ? 130 : 170 }}px, 1fr));">
                        {{-- Header --}}
                        <div class="sticky top-0 z-20 border-b border-gray-200 bg-white dark:border-white/10 dark:bg-gray-900"></div>
                        @foreach ($columns as $col)
                            <div class="sticky top-0 z-20 flex items-center gap-2 border-b border-s border-gray-200 bg-white px-3 py-2 dark:border-white/10 dark:bg-gray-900">
                                @if ($mode === 'day')
                                    <span class="flex h-8 w-8 shrink-0 items-center justify-center overflow-hidden rounded-full text-xs font-semibold" style="background: {{ $col['color'] }}33; color: {{ $col['color'] }}">
                                        @if ($col['photo'])<img src="{{ $col['photo'] }}" alt="" class="h-full w-full object-cover">@else{{ mb_substr($col['label'], 0, 1) }}@endif
                                    </span>
                                @endif
                                <span class="min-w-0">
                                    <span @class(['block truncate text-sm font-medium', 'text-primary-600' => $col['date'] === $todayDate && $mode === 'week'])>{{ $col['label'] }}</span>
                                    <span class="block truncate text-xs text-gray-500">{{ $col['sub'] }}</span>
                                </span>
                            </div>
                        @endforeach

                        {{-- Time gutter --}}
                        <div class="relative border-gray-200 dark:border-white/10" style="height: {{ $height }}px">
                            @for ($m = $open; $m < $close; $m += 60)
                                <span class="absolute end-2 -translate-y-1/2 text-[11px] text-gray-400" style="top: {{ ($m - $open) * $pxPerMinute }}px" dir="ltr">{{ sprintf('%02d:00', intdiv($m, 60)) }}</span>
                            @endfor
                        </div>

                        {{-- Columns --}}
                        @foreach ($columns as $col)
                            <div class="relative border-s border-gray-200 dark:border-white/10"
                                 style="height: {{ $height }}px; background-image: repeating-linear-gradient(to bottom, rgba(120,113,108,.14) 0, rgba(120,113,108,.14) 1px, transparent 1px, transparent {{ 60 * $pxPerMinute }}px), repeating-linear-gradient(to bottom, rgba(120,113,108,.06) 0, rgba(120,113,108,.06) 1px, transparent 1px, transparent {{ 15 * $pxPerMinute }}px);"
                                 data-column data-staff="{{ $col['staff'] }}" data-date="{{ $col['date'] }}"
                                 @click.self="create($event, {{ $col['staff'] ?? 'null' }}, '{{ $col['date'] }}')">
                                @foreach ($blocks->filter(fn ($b) => $mode === 'week' ? $b['date'] === $col['date'] : ($b['staff_id'] === $col['staff'] && $b['date'] === $date)) as $block)
                                    <div class="pointer-events-none absolute inset-x-0 overflow-hidden px-2 py-1 text-[11px] text-gray-500" style="top: {{ $block['top'] }}px; height: {{ $block['height'] }}px; background: repeating-linear-gradient(135deg, rgba(120,113,108,.10) 0 6px, rgba(120,113,108,.18) 6px 12px);">
                                        {{ $block['type'] }}@if ($block['reason']) · {{ $block['reason'] }}@endif
                                    </div>
                                @endforeach

                                @if ($nowMinute !== null && $nowMinute >= $open && $nowMinute <= $close && $col['date'] === $todayDate)
                                    <div class="pointer-events-none absolute inset-x-0 z-10 border-t-2 border-danger-500" style="top: {{ ($nowMinute - $open) * $pxPerMinute }}px"></div>
                                @endif

                                @foreach ($events->filter(fn ($e) => $mode === 'week' ? $e['date'] === $col['date'] : ($e['staff_id'] === $col['staff'] && $e['date'] === $date)) as $ev)
                                    <div data-event wire:key="ev-{{ $ev['id'] }}-{{ $ev['top'] }}-{{ $ev['height'] }}-{{ $ev['staff_id'] }}"
                                         class="group absolute z-[5] cursor-grab select-none overflow-hidden rounded-lg border-s-4 bg-white px-2 py-1 text-xs shadow-sm ring-1 ring-black/5 transition-shadow hover:shadow-md active:cursor-grabbing dark:bg-gray-800"
                                         style="top: {{ $ev['top'] }}px; height: {{ $ev['height'] }}px; border-inline-start-color: {{ $ev['color'] }}; background-color: {{ $ev['color'] }}14;
                                                @if ($mode === 'week') inset-inline-start: calc({{ $ev['lane'] }} / {{ $ev['lanes'] }} * 100% + 2px); width: calc(100% / {{ $ev['lanes'] }} - 4px); @else inset-inline: 4px; @endif"
                                         @pointerdown="start($event, @js($ev), 'move')" tabindex="0" @keydown.enter="select(@js($ev))"
                                         role="button" aria-label="{{ $ev['time'] }} {{ $ev['client'] }} {{ $ev['service'] }}">
                                        <p class="truncate font-semibold" dir="auto">{{ $ev['client'] }}</p>
                                        <p class="truncate text-gray-600 dark:text-gray-300">{{ $ev['service'] }}</p>
                                        <p class="truncate text-[10px] text-gray-500" dir="ltr">{{ $ev['time'] }}@if ($mode === 'week' && $ev['staff']) · {{ $ev['staff'] }}@endif</p>
                                        @if ($ev['status'] === 'checked_in' || $ev['status'] === 'in_service')<span class="absolute end-1 top-1 h-2 w-2 rounded-full bg-primary-500" title="{{ $ev['status_label'] }}"></span>@endif
                                        @if ($canManage)
                                            <span class="absolute inset-x-0 bottom-0 h-2 cursor-ns-resize opacity-0 group-hover:opacity-100" style="background: {{ $ev['color'] }}55" @pointerdown.stop="start($event, @js($ev), 'resize')" aria-hidden="true"></span>
                                        @endif
                                    </div>
                                @endforeach
                            </div>
                        @endforeach
                    </div>
                </div>

                {{-- Legend --}}
                @if ($colorBy === 'status')
                    <div class="flex flex-wrap gap-3 text-xs text-gray-500">
                        @foreach ($statusColors as $status => $color)
                            <span class="inline-flex items-center gap-1.5"><span class="h-2.5 w-2.5 rounded-full" style="background: {{ $color }}"></span>{{ __('booking.status.'.$status) }}</span>
                        @endforeach
                    </div>
                @endif
            @endif

            {{-- Selected appointment --}}
            <div x-show="selected" x-cloak x-transition.opacity.duration.150ms class="fixed bottom-6 end-6 z-40 w-80 rounded-2xl border border-gray-200 bg-white p-5 shadow-xl dark:border-white/10 dark:bg-gray-900" @keydown.escape.window="selected = null">
                <div class="flex items-start justify-between gap-3">
                    <div class="min-w-0">
                        <p class="truncate font-semibold" x-text="selected?.client"></p>
                        <p class="text-xs text-gray-500" dir="ltr" x-text="selected?.phone"></p>
                    </div>
                    <button type="button" class="text-gray-400 hover:text-gray-700" @click="selected = null" aria-label="{{ __('salon.ui.cancel') }}">✕</button>
                </div>
                <p class="mt-3 text-sm" x-text="selected?.service"></p>
                <p class="text-xs text-gray-500"><span dir="ltr" x-text="selected?.time"></span> · <span x-text="selected?.staff"></span></p>
                <p class="mt-2 inline-flex rounded-full bg-gray-100 px-2 py-0.5 text-xs dark:bg-white/10" x-text="selected?.status_label"></p>
                <p class="mt-1 font-mono text-xs text-gray-400" dir="ltr" x-text="selected?.reference"></p>
                <div class="mt-4 flex gap-2">
                    @if ($canManage)
                        <button type="button" x-show="selected?.can_check_in" class="fi-btn rounded-lg bg-primary-600 px-3 py-2 text-sm font-medium text-white hover:bg-primary-500" @click="$wire.checkIn(selected.booking_id); selected = null">{{ __('booking.admin.actions.checkIn') }}</button>
                    @endif
                    <a :href="selected?.url" class="rounded-lg border border-gray-200 px-3 py-2 text-sm hover:bg-gray-50 dark:border-white/10 dark:hover:bg-white/5">{{ __('salon.ui.view') }}</a>
                </div>
            </div>
        </div>
    @endif

    @script
    <script>
        Alpine.data('staffCalendar', (cfg) => ({
            ...cfg, drag: null, selected: null,
            init() {
                this.onMove = (e) => this.move(e);
                this.onUp = (e) => this.end(e);
                if (window.Echo && this.channel) {
                    try { window.Echo.private(this.channel).listen('.booking.updated', () => $wire.$refresh()); } catch (e) {}
                }
            },
            snap(px) { const step = 15 * this.ppm; return Math.round(px / step) * step; },
            start(e, ev, kind) {
                if (e.button !== undefined && e.button !== 0) return;
                const el = e.target.closest('[data-event]');
                this.drag = { ev, kind, el, x: e.clientX, y: e.clientY, dx: 0, dy: 0, moved: false, height: el.offsetHeight };
                window.addEventListener('pointermove', this.onMove);
                window.addEventListener('pointerup', this.onUp, { once: true });
            },
            move(e) {
                const d = this.drag; if (!d) return;
                d.dx = e.clientX - d.x; d.dy = e.clientY - d.y;
                if (!d.moved && Math.abs(d.dx) + Math.abs(d.dy) < 6) return;
                if (!this.canManage) return;
                d.moved = true;
                d.el.style.zIndex = 30; d.el.style.opacity = '.85';
                if (d.kind === 'move') { d.el.style.transform = `translate(${d.dx}px, ${this.snap(d.dy)}px)`; }
                else { d.el.style.height = Math.max(15 * this.ppm, d.height + this.snap(d.dy)) + 'px'; }
            },
            end(e) {
                window.removeEventListener('pointermove', this.onMove);
                const d = this.drag; this.drag = null; if (!d) return;
                if (!d.moved) { this.select(d.ev); return; }
                const minutes = Math.round(this.snap(d.dy) / this.ppm);
                d.el.style.pointerEvents = 'none';
                if (d.kind === 'resize') {
                    $wire.resizeItem(d.ev.id, Math.max(15, d.ev.duration + minutes));
                } else {
                    const col = document.elementsFromPoint(e.clientX, e.clientY).find((n) => n.hasAttribute && n.hasAttribute('data-column'));
                    const staff = col && col.dataset.staff ? parseInt(col.dataset.staff) : null;
                    const date = col ? col.dataset.date : d.ev.date;
                    $wire.moveItem(d.ev.id, staff, date, d.ev.start + minutes);
                }
                setTimeout(() => { d.el.style.transform = ''; d.el.style.height = ''; d.el.style.opacity = ''; d.el.style.zIndex = ''; d.el.style.pointerEvents = ''; }, 600);
            },
            select(ev) { this.selected = ev; },
            create(e, staff, date) {
                if (!this.canManage) return;
                const minutes = this.open + Math.floor(e.offsetY / this.ppm / 15) * 15;
                const time = String(Math.floor(minutes / 60)).padStart(2, '0') + ':' + String(minutes % 60).padStart(2, '0');
                $wire.mountAction('quickCreate', { staff, date, time });
            },
        }));
    </script>
    @endscript
</x-filament-panels::page>

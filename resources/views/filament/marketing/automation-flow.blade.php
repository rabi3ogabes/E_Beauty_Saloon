<x-filament-panels::page>
    @php
        $icons = ['wait' => '⏳', 'condition' => '◇', 'send_message' => '✉', 'award_points' => '★', 'add_tag' => '#', 'coupon' => '%'];
        $configKeys = ['days', 'hours_before', 'below', 'days_before'];
    @endphp
    <div x-data="{
            steps: $wire.entangle('steps'),
            blank: @js($blank),
            add(index, type = 'send_message') { const s = JSON.parse(JSON.stringify(this.blank)); s.type = type; this.steps.splice(index, 0, s) },
            remove(i) { this.steps.splice(i, 1) },
            move(i, d) { const j = i + d; if (j < 0 || j >= this.steps.length) return; const s = this.steps.splice(i, 1)[0]; this.steps.splice(j, 0, s) },
            toggleChannel(step, c) { step.channels = step.channels.includes(c) ? step.channels.filter(x => x !== c) : [...step.channels, c] },
         }" class="mx-auto w-full max-w-3xl">

        {{-- Header --}}
        <x-filament::section>
            <div class="grid gap-4 sm:grid-cols-[1fr_auto] sm:items-end">
                <label class="block">
                    <span class="text-sm font-medium text-gray-700 dark:text-gray-200">{{ __('marketing.common.name') }}</span>
                    <x-filament::input.wrapper class="mt-1"><x-filament::input type="text" wire:model="name" /></x-filament::input.wrapper>
                </label>
                <label class="flex items-center gap-3 text-sm font-medium">
                    <x-filament::input.checkbox wire:model="isActive" />{{ __('marketing.common.active') }}
                </label>
            </div>
        </x-filament::section>

        {{-- Trigger --}}
        <div class="relative mt-6">
            <div class="rounded-2xl border border-primary-300 bg-primary-50/60 p-5 shadow-sm dark:border-primary-500/40 dark:bg-primary-500/10">
                <p class="text-xs font-semibold uppercase tracking-[0.2em] text-primary-700 dark:text-primary-300">{{ __('marketing.automations.when') }}</p>
                <div class="mt-3 grid gap-3 sm:grid-cols-2">
                    <x-filament::input.wrapper>
                        <x-filament::input.select wire:model.live="trigger">
                            @foreach ($triggers as $key => $label)<option value="{{ $key }}">{{ $label }}</option>@endforeach
                        </x-filament::input.select>
                    </x-filament::input.wrapper>
                    @foreach ($configKeys as $key)
                        @if (array_key_exists($key, $this->triggerConfig))
                            <label class="flex items-center gap-2 text-sm">
                                <span class="shrink-0 text-gray-600 dark:text-gray-300">{{ __('marketing.automations.config.'.$key) }}</span>
                                <x-filament::input.wrapper class="w-28"><x-filament::input type="number" min="0" wire:model="triggerConfig.{{ $key }}" /></x-filament::input.wrapper>
                            </label>
                        @endif
                    @endforeach
                </div>
            </div>
        </div>

        {{-- Steps --}}
        <template x-for="(step, i) in steps" :key="i">
            <div>
                <div class="flex flex-col items-center py-1" aria-hidden="true">
                    <div class="h-5 w-px bg-gray-300 dark:bg-white/15"></div>
                    <button type="button" @click="add(i)" class="flex h-7 w-7 items-center justify-center rounded-full border border-gray-300 bg-white text-gray-500 transition hover:border-primary-400 hover:text-primary-600 dark:border-white/15 dark:bg-gray-900" title="{{ __('marketing.automations.add_step') }}">+</button>
                    <div class="h-5 w-px bg-gray-300 dark:bg-white/15"></div>
                </div>

                <div class="group rounded-2xl border border-gray-200 bg-white p-5 shadow-sm transition duration-200 hover:shadow-md dark:border-white/10 dark:bg-gray-900">
                    <div class="flex flex-wrap items-center gap-3">
                        <span class="flex h-9 w-9 items-center justify-center rounded-full bg-gray-100 text-base dark:bg-white/5"
                              x-text="{{ \Illuminate\Support\Js::from($icons) }}[step.type]"></span>
                        <x-filament::input.wrapper class="min-w-48 flex-1">
                            <select x-model="step.type" class="fi-select-input block w-full border-none bg-transparent py-1.5 ps-3 pe-8 text-sm">
                                @foreach ($stepTypes as $key => $label)<option value="{{ $key }}">{{ $label }}</option>@endforeach
                            </select>
                        </x-filament::input.wrapper>
                        <div class="ms-auto flex gap-1 opacity-60 transition group-hover:opacity-100">
                            <x-filament::icon-button icon="heroicon-m-arrow-up" color="gray" size="sm" x-on:click="move(i, -1)" :label="__('marketing.automations.move_up')" />
                            <x-filament::icon-button icon="heroicon-m-arrow-down" color="gray" size="sm" x-on:click="move(i, 1)" :label="__('marketing.automations.move_down')" />
                            <x-filament::icon-button icon="heroicon-m-trash" color="danger" size="sm" x-on:click="remove(i)" :label="__('marketing.automations.remove_step')" />
                        </div>
                    </div>

                    {{-- wait --}}
                    <div x-show="step.type === 'wait'" class="mt-4 grid grid-cols-3 gap-3 text-sm">
                        @foreach (['days', 'hours', 'minutes'] as $unit)
                            <label><span class="text-gray-500">{{ __('marketing.automations.fields.'.$unit) }}</span>
                                <x-filament::input.wrapper class="mt-1"><input type="number" min="0" x-model.number="step.{{ $unit }}" class="fi-input block w-full border-none bg-transparent px-3 py-1.5 text-sm"></x-filament::input.wrapper></label>
                        @endforeach
                    </div>

                    {{-- condition --}}
                    <div x-show="step.type === 'condition'" class="mt-4 grid gap-3 sm:grid-cols-2 text-sm">
                        <x-filament::input.wrapper>
                            <select x-model="step.check" class="fi-select-input block w-full border-none bg-transparent py-1.5 ps-3 pe-8 text-sm">
                                @foreach ($checks as $key => $label)<option value="{{ $key }}">{{ $label }}</option>@endforeach
                            </select>
                        </x-filament::input.wrapper>
                        <x-filament::input.wrapper x-show="['has_consent','tier_is','has_tag','wallet_below'].includes(step.check)">
                            <input type="text" x-model="step.value" placeholder="{{ __('marketing.automations.fields.value') }}" class="fi-input block w-full border-none bg-transparent px-3 py-1.5 text-sm">
                        </x-filament::input.wrapper>
                    </div>

                    {{-- send message --}}
                    <div x-show="step.type === 'send_message'" class="mt-4 space-y-3 text-sm">
                        <div>
                            <span class="text-gray-500">{{ __('marketing.automations.fields.channels') }}</span>
                            <div class="mt-1 flex flex-wrap gap-2">
                                @foreach (['whatsapp', 'sms', 'email', 'push'] as $c)
                                    <button type="button" @click="toggleChannel(step, '{{ $c }}')"
                                            :class="step.channels.includes('{{ $c }}') ? 'bg-primary-600 text-white border-primary-600' : 'bg-white text-gray-600 border-gray-300 dark:bg-gray-900 dark:text-gray-300 dark:border-white/15'"
                                            class="rounded-full border px-3 py-1 text-xs font-medium transition">
                                        <span x-text="step.channels.includes('{{ $c }}') ? (step.channels.indexOf('{{ $c }}') + 1) + '. ' : ''"></span>{{ __('marketing.channels.'.$c) }}
                                    </button>
                                @endforeach
                            </div>
                        </div>
                        <div class="grid gap-3 sm:grid-cols-2">
                            <label><span class="text-gray-500">{{ __('marketing.automations.fields.body_en') }}</span>
                                <textarea x-model="step.body.en" rows="4" dir="ltr" class="mt-1 block w-full rounded-lg border border-gray-300 bg-white p-2 text-sm dark:border-white/10 dark:bg-white/5"></textarea></label>
                            <label><span class="text-gray-500">{{ __('marketing.automations.fields.body_ar') }}</span>
                                <textarea x-model="step.body.ar" rows="4" dir="rtl" class="mt-1 block w-full rounded-lg border border-gray-300 bg-white p-2 text-sm dark:border-white/10 dark:bg-white/5"></textarea></label>
                        </div>
                        <div class="grid gap-3 sm:grid-cols-2">
                            <label><span class="text-gray-500">{{ __('marketing.automations.fields.template') }}</span>
                                <x-filament::input.wrapper class="mt-1">
                                    <select x-model="step.template" class="fi-select-input block w-full border-none bg-transparent py-1.5 ps-3 pe-8 text-sm">
                                        <option value="">—</option>
                                        @foreach ($templates as $t)<option value="{{ $t }}">{{ $t }}</option>@endforeach
                                    </select>
                                </x-filament::input.wrapper></label>
                            <label><span class="text-gray-500">{{ __('marketing.automations.fields.link') }}</span>
                                <x-filament::input.wrapper class="mt-1"><input type="text" x-model="step.link" placeholder="/book" dir="ltr" class="fi-input block w-full border-none bg-transparent px-3 py-1.5 text-sm"></x-filament::input.wrapper></label>
                        </div>
                        <label class="flex items-center gap-2"><input type="checkbox" x-model="step.transactional" class="rounded border-gray-300 text-primary-600"> {{ __('marketing.automations.fields.transactional') }}</label>
                    </div>

                    {{-- points / tag / coupon --}}
                    <div x-show="step.type === 'award_points'" class="mt-4 text-sm"><label><span class="text-gray-500">{{ __('marketing.automations.fields.points') }}</span>
                        <x-filament::input.wrapper class="mt-1 w-40"><input type="number" min="1" x-model.number="step.points" class="fi-input block w-full border-none bg-transparent px-3 py-1.5 text-sm"></x-filament::input.wrapper></label></div>
                    <div x-show="step.type === 'add_tag'" class="mt-4 text-sm"><label><span class="text-gray-500">{{ __('marketing.automations.fields.tag') }}</span>
                        <x-filament::input.wrapper class="mt-1"><input type="text" x-model="step.tag" class="fi-input block w-full border-none bg-transparent px-3 py-1.5 text-sm"></x-filament::input.wrapper></label></div>
                    <div x-show="step.type === 'coupon'" class="mt-4 grid grid-cols-2 gap-3 text-sm">
                        <label><span class="text-gray-500">{{ __('marketing.automations.fields.discount') }}</span>
                            <x-filament::input.wrapper class="mt-1"><input type="number" min="1" max="100" :value="step.discount_bp / 100" @input="step.discount_bp = Math.round($event.target.value * 100)" class="fi-input block w-full border-none bg-transparent px-3 py-1.5 text-sm"></x-filament::input.wrapper></label>
                        <label><span class="text-gray-500">{{ __('marketing.automations.fields.valid_days') }}</span>
                            <x-filament::input.wrapper class="mt-1"><input type="number" min="1" x-model.number="step.valid_days" class="fi-input block w-full border-none bg-transparent px-3 py-1.5 text-sm"></x-filament::input.wrapper></label>
                    </div>
                </div>
            </div>
        </template>

        <div class="flex flex-col items-center py-1">
            <div class="h-5 w-px bg-gray-300 dark:bg-white/15"></div>
            <x-filament::button color="gray" icon="heroicon-m-plus" size="sm" x-on:click="add(steps.length)">{{ __('marketing.automations.add_step') }}</x-filament::button>
        </div>

        <p class="mt-6 text-center text-xs text-gray-500">{{ __('marketing.automations.placeholders') }}</p>
        @error('steps.*') <p class="mt-2 text-center text-sm text-danger-600">{{ $message }}</p> @enderror
        @foreach ($errors->all() as $error)<p class="mt-2 text-center text-sm text-danger-600">{{ $error }}</p>@endforeach

        <div class="sticky bottom-4 mt-6 flex justify-center">
            <x-filament::button wire:click="save" icon="heroicon-o-check" size="lg" class="shadow-lg">{{ __('marketing.automations.save_flow') }}</x-filament::button>
        </div>
    </div>
</x-filament-panels::page>

<x-filament-panels::page>
    @php($fontQuery = collect($config['fonts'])->map(fn ($f) => 'family='.str_replace(' ', '+', $f).':wght@400;500;600;700')->implode('&'))
    <link rel="stylesheet" href="https://fonts.googleapis.com/css2?{{ $fontQuery }}&display=swap">
    <script>{!! $script !!}</script>

    <div x-data="window.luxeBannerStudio ? luxeBannerStudio(@js($config)) : {}" x-on:pointermove.window="onDrag($event)" x-on:pointerup.window="endDrag()"
         class="grid gap-6 lg:grid-cols-[22rem_1fr]">

        {{-- Controls --}}
        <div class="space-y-5">
            <x-filament::section>
                <label class="block text-sm font-medium">{{ __('marketing.common.name') }}
                    <x-filament::input.wrapper class="mt-1"><x-filament::input type="text" wire:model="name" /></x-filament::input.wrapper>
                </label>
                <p class="mt-4 text-sm font-medium">{{ __('marketing.banners.format') }}</p>
                <div class="mt-2 grid grid-cols-3 gap-2">
                    @foreach (array_keys($config['formats']) as $f)
                        <button type="button" @click="setFormat('{{ $f }}')" :class="format === '{{ $f }}' ? 'border-primary-500 bg-primary-50 text-primary-700 dark:bg-primary-500/10' : 'border-gray-200 dark:border-white/10'"
                                class="rounded-xl border px-2 py-2 text-xs font-medium transition">{{ __('marketing.banners.formats.'.$f) }}</button>
                    @endforeach
                </div>
                <p class="mt-4 text-sm font-medium">{{ __('marketing.banners.template') }}</p>
                <div class="mt-2 grid grid-cols-2 gap-2">
                    @foreach (['editorial', 'minimal', 'offer', 'arabic'] as $tpl)
                        <button type="button" @click="applyTemplate('{{ $tpl }}')" :class="template === '{{ $tpl }}' ? 'border-primary-500 bg-primary-50 text-primary-700 dark:bg-primary-500/10' : 'border-gray-200 dark:border-white/10'"
                                class="rounded-xl border px-3 py-2 text-xs font-medium transition">{{ __('marketing.banners.templates.'.$tpl) }}</button>
                    @endforeach
                </div>
            </x-filament::section>

            <x-filament::section :heading="__('marketing.common.generate_ai')" icon="heroicon-o-sparkles">
                <textarea x-model="aiPrompt" rows="2" placeholder="{{ __('marketing.banners.ai_prompt') }}" class="block w-full rounded-lg border border-gray-300 bg-white p-2 text-sm dark:border-white/10 dark:bg-white/5"></textarea>
                <x-filament::button class="mt-3 w-full" color="gray" icon="heroicon-o-sparkles" x-on:click="generate()" x-bind:disabled="busy">{{ __('marketing.common.generate_ai') }}</x-filament::button>
            </x-filament::section>

            <x-filament::section>
                <div class="flex gap-2">
                    <x-filament::button size="sm" color="gray" icon="heroicon-m-plus" x-on:click="addText()">{{ __('marketing.banners.add_text') }}</x-filament::button>
                    <x-filament::button size="sm" color="gray" icon="heroicon-m-photo" x-on:click="addLogo()">{{ __('marketing.banners.add_logo') }}</x-filament::button>
                </div>

                <div class="mt-4 space-y-3 text-sm">
                    <p class="font-medium">{{ __('marketing.banners.background') }}</p>
                    <div class="flex items-center gap-2">
                        <select x-model="design.background.type" class="rounded-lg border border-gray-300 bg-white px-2 py-1.5 text-sm dark:border-white/10 dark:bg-white/5">
                            <option value="gradient">Gradient</option><option value="solid">Solid</option><option value="image">Image</option>
                        </select>
                        <template x-if="design.background.type === 'gradient'"><span class="flex gap-1"><input type="color" x-model="design.background.from" class="h-8 w-10 rounded"><input type="color" x-model="design.background.to" class="h-8 w-10 rounded"></span></template>
                        <template x-if="design.background.type === 'solid'"><input type="color" x-model="design.background.color" class="h-8 w-10 rounded"></template>
                    </div>
                    <template x-if="design.background.type === 'image'">
                        <input type="url" x-model="design.background.image" placeholder="{{ __('marketing.banners.image_url') }}" class="block w-full rounded-lg border border-gray-300 bg-white px-2 py-1.5 text-sm dark:border-white/10 dark:bg-white/5">
                    </template>
                </div>

                <template x-if="current && current.type !== 'logo'">
                    <div class="mt-5 space-y-3 border-t border-gray-100 pt-4 text-sm dark:border-white/5">
                        <textarea x-model="current.text" rows="2" :dir="/[؀-ۿ]/.test(current.text) ? 'rtl' : 'ltr'" class="block w-full rounded-lg border border-gray-300 bg-white p-2 text-sm dark:border-white/10 dark:bg-white/5"></textarea>
                        <div class="grid grid-cols-2 gap-2">
                            <label>{{ __('marketing.banners.font') }}
                                <select x-model="current.font" class="mt-1 block w-full rounded-lg border border-gray-300 bg-white px-2 py-1.5 text-sm dark:border-white/10 dark:bg-white/5">
                                    <template x-for="f in fonts"><option :value="f" x-text="f" :selected="f === current.font"></option></template>
                                </select></label>
                            <label>{{ __('marketing.banners.size') }}<input type="range" min="16" max="220" x-model.number="current.size" class="mt-3 block w-full"></label>
                            <label>{{ __('marketing.banners.color') }}<input type="color" x-model="current.color" class="mt-1 block h-8 w-full rounded"></label>
                            <label x-show="current.type === 'pill'">Pill<input type="color" x-model="current.bg" class="mt-1 block h-8 w-full rounded"></label>
                            <label>{{ __('marketing.banners.align') }}
                                <select x-model="current.align" class="mt-1 block w-full rounded-lg border border-gray-300 bg-white px-2 py-1.5 text-sm dark:border-white/10 dark:bg-white/5">
                                    <option value="left">⟸</option><option value="center">≡</option><option value="right">⟹</option>
                                </select></label>
                            <label>W %<input type="range" min="10" max="96" x-model.number="current.w" class="mt-3 block w-full"></label>
                        </div>
                        <x-filament::button size="xs" color="danger" icon="heroicon-m-trash" x-on:click="removeSelected()">{{ __('marketing.banners.delete_element') }}</x-filament::button>
                    </div>
                </template>
                <template x-if="current && current.type === 'logo'">
                    <div class="mt-5 space-y-2 border-t border-gray-100 pt-4 text-sm dark:border-white/5">
                        <label>W %<input type="range" min="8" max="60" x-model.number="current.w" class="mt-2 block w-full"></label>
                        <x-filament::button size="xs" color="danger" icon="heroicon-m-trash" x-on:click="removeSelected()">{{ __('marketing.banners.delete_element') }}</x-filament::button>
                    </div>
                </template>
            </x-filament::section>
        </div>

        {{-- Stage --}}
        <div class="flex flex-col items-center gap-4">
            <p class="text-xs text-gray-500">{{ __('marketing.banners.drag_hint') }}</p>
            <div class="w-full" :class="format === 'story' ? 'max-w-sm' : (format === 'whatsapp' ? 'max-w-3xl' : 'max-w-xl')">
                <div x-ref="stage" class="relative w-full touch-none select-none overflow-hidden rounded-2xl shadow-xl ring-1 ring-black/5" :style="`aspect-ratio: ${ratio}; ${bgStyle()}`" @pointerdown.self="selected = null">
                    <div class="pointer-events-none absolute inset-[3.5%] border border-[#C8A96A]/50"></div>
                    <template x-for="el in design.elements" :key="el.id">
                        <div class="absolute cursor-move" :style="elStyle(el)" @pointerdown.prevent="startDrag($event, el)"
                             :class="selected === el.id ? 'outline outline-2 outline-offset-4 outline-primary-500/80' : 'hover:outline hover:outline-1 hover:outline-offset-4 hover:outline-primary-300'"
                             :dir="/[؀-ۿ]/.test(el.text || '') ? 'rtl' : 'ltr'">
                            <template x-if="el.type === 'logo'">
                                <div class="text-center">
                                    @if ($config['logoUrl'])<img src="{{ $config['logoUrl'] }}" alt="" class="mx-auto w-full" draggable="false">@else<span class="font-display text-sm tracking-[0.3em] text-gray-700">{{ tenant()->name }}</span>@endif
                                </div>
                            </template>
                            <template x-if="el.type === 'text'"><div class="whitespace-pre-line" x-text="el.text"></div></template>
                            <template x-if="el.type === 'pill'"><div class="text-center"><span :style="pillStyle(el)" x-text="el.text"></span></div></template>
                        </div>
                    </template>
                </div>
            </div>
            <div class="flex flex-wrap justify-center gap-3">
                <x-filament::button color="gray" icon="heroicon-o-arrow-down-tray" x-on:click="exportPng(true)">{{ __('marketing.banners.export') }}</x-filament::button>
                <x-filament::button icon="heroicon-o-check" x-on:click="save()" x-bind:disabled="busy">
                    <span x-show="!busy">{{ __('marketing.banners.save') }}</span><x-filament::loading-indicator x-show="busy" class="h-5 w-5" />
                </x-filament::button>
            </div>
            @if ($exportedUrl)<a href="{{ $exportedUrl }}" target="_blank" class="text-xs text-primary-600 underline">PNG ↗</a>@endif
        </div>
    </div>
</x-filament-panels::page>

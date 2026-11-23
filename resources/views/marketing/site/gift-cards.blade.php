<x-layouts.site :title="__('marketing.gift_cards.title')" :description="__('marketing.gift_cards.subtitle')">
    @php
        $currency = $currentTenant->currency ?? 'QAR';
        $presetsMajor = array_map(fn ($p) => $p / 100, $presets);
    @endphp
    <section class="container-luxe py-12 sm:py-16"
             x-data="{
                design: @js(old('design', 'champagne')),
                amount: @js((float) old('amount', $presetsMajor[1] ?? 500)),
                to: @js(old('recipient_name', '')),
                from: @js(old('purchaser_name', $client?->name ?? '')),
                get amountLabel() { return window.luxe ? window.luxe.money(Math.round((Number(this.amount) || 0) * 100), @js($currency)) : this.amount },
             }">
        <div class="max-w-2xl" data-reveal>
            <p class="eyebrow">{{ __('marketing.gift_cards.eyebrow') }}</p>
            <h1 class="mt-3 text-4xl font-semibold sm:text-5xl">{{ __('marketing.gift_cards.title') }}</h1>
            <p class="mt-4 text-lg text-muted">{{ __('marketing.gift_cards.subtitle') }}</p>
        </div>

        <div class="mt-10 grid gap-10 lg:grid-cols-[1.05fr_1fr] lg:items-start">
            <div class="lg:sticky lg:top-24">
                @include('marketing.partials.gift-card-art', ['alpine' => true, 'design' => 'champagne', 'amount' => '', 'to' => '', 'from' => '', 'salon' => $currentTenant->name])
                <div class="mt-5 flex justify-center gap-3" role="radiogroup" aria-label="{{ __('marketing.gift_cards.choose_design') }}">
                    @foreach ($designs as $d)
                        <button type="button" role="radio" :aria-checked="design === '{{ $d }}'" @click="design = '{{ $d }}'"
                                :class="design === '{{ $d }}' ? 'chip-active' : ''" class="chip min-h-11 px-4">{{ __('marketing.gift_cards.designs.'.$d) }}</button>
                    @endforeach
                </div>
            </div>

            <form method="POST" action="{{ route('marketing.gift-cards.store') }}" class="card space-y-6 p-6 sm:p-8">
                @csrf
                <input type="hidden" name="design" :value="design">

                <fieldset>
                    <legend class="label">{{ __('marketing.gift_cards.amount') }}</legend>
                    <div class="mt-2 flex flex-wrap gap-2">
                        @foreach ($presetsMajor as $p)
                            <button type="button" @click="amount = {{ $p }}" :class="Number(amount) === {{ $p }} ? 'chip-active' : ''" class="chip min-h-11 px-4">{{ money((int) ($p * 100)) }}</button>
                        @endforeach
                    </div>
                    <div class="mt-3"><input name="amount" type="number" step="1" min="50" max="10000" x-model="amount" class="input" inputmode="decimal" aria-label="{{ __('marketing.gift_cards.custom_amount') }}" required></div>
                </fieldset>

                <div class="grid gap-5 sm:grid-cols-2">
                    <div class="sm:col-span-2"><p class="eyebrow">{{ __('marketing.gift_cards.recipient') }}</p></div>
                    <div><label class="label" for="recipient_name">{{ __('marketing.gift_cards.recipient_name') }}</label><input id="recipient_name" name="recipient_name" x-model="to" class="input" required maxlength="100"></div>
                    <div><label class="label" for="recipient_phone">{{ __('marketing.gift_cards.recipient_phone') }}</label><input id="recipient_phone" name="recipient_phone" value="{{ old('recipient_phone') }}" class="input" dir="ltr" inputmode="tel" placeholder="+974"></div>
                    <div class="sm:col-span-2"><label class="label" for="recipient_email">{{ __('marketing.gift_cards.recipient_email') }}</label><input id="recipient_email" name="recipient_email" type="email" value="{{ old('recipient_email') }}" class="input" dir="ltr"></div>
                    <div class="sm:col-span-2"><label class="label" for="message">{{ __('marketing.gift_cards.message') }}</label><textarea id="message" name="message" rows="3" maxlength="300" class="input" placeholder="{{ __('marketing.gift_cards.message_placeholder') }}">{{ old('message') }}</textarea></div>

                    <div class="sm:col-span-2 pt-2"><p class="eyebrow">{{ __('marketing.gift_cards.from_you') }}</p></div>
                    <div><label class="label" for="purchaser_name">{{ __('marketing.gift_cards.your_name') }}</label><input id="purchaser_name" name="purchaser_name" x-model="from" class="input" required maxlength="100"></div>
                    <div><label class="label" for="purchaser_phone">{{ __('marketing.gift_cards.your_phone') }}</label><input id="purchaser_phone" name="purchaser_phone" value="{{ old('purchaser_phone', $client?->phone) }}" class="input" dir="ltr" inputmode="tel"></div>
                    <div class="sm:col-span-2"><label class="label" for="purchaser_email">{{ __('marketing.gift_cards.your_email') }}</label><input id="purchaser_email" name="purchaser_email" type="email" value="{{ old('purchaser_email', $client?->email) }}" class="input" dir="ltr"></div>
                </div>

                @if ($errors->any())<p class="rounded-xl bg-danger/10 px-4 py-3 text-sm text-danger">{{ $errors->first() }}</p>@endif

                @if ($canPay)
                    <button class="btn-primary w-full">{{ __('marketing.gift_cards.pay') }} · <span x-text="amountLabel"></span></button>
                    <p class="text-center text-xs text-muted">{{ __('marketing.gift_cards.delivery_note') }}</p>
                @else
                    <p class="rounded-xl bg-ivory px-4 py-3 text-sm text-muted">{{ __('marketing.gift_cards.unavailable') }}</p>
                @endif
            </form>
        </div>
    </section>
</x-layouts.site>

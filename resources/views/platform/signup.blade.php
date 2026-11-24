<x-layouts.platform :title="__('platform.signup.title')" :description="__('platform.signup.meta')">
    <section class="container-luxe grid gap-12 py-16 md:grid-cols-[minmax(0,1fr)_1.15fr] md:py-24">
        <div>
            <p class="eyebrow">{{ __('platform.cta.start_trial') }}</p>
            <h1 class="mt-4 text-[clamp(2.2rem,5vw,4rem)] leading-[1.05] font-semibold">{{ __('platform.signup.title') }}</h1>
            <p class="mt-5 max-w-md text-lg text-muted">{{ __('platform.signup.body') }}</p>
            <ol class="mt-10 space-y-5">
                @foreach (__('platform.signup.steps') as $i => $step)
                    <li class="flex gap-4"><span class="flex h-8 w-8 shrink-0 items-center justify-center rounded-full bg-gold/15 font-display text-gold-dark">{{ $i + 1 }}</span><span class="pt-1">{{ $step }}</span></li>
                @endforeach
            </ol>
        </div>
        <form method="POST" action="{{ route('platform.signup.store') }}" class="card space-y-5 p-6 sm:p-8" x-data="{ name: @js(old('salon_name', '')), slug: @js(old('slug', '')), touched: false }">
            @csrf
            <div>
                <label class="label" for="plan">{{ __('platform.signup.plan') }}</label>
                <div class="grid gap-2 sm:grid-cols-3">
                    @foreach ($plans as $plan)
                        <label class="chip min-h-14 cursor-pointer justify-between rounded-xl px-4 has-[:checked]:border-ink has-[:checked]:bg-ink has-[:checked]:text-ivory">
                            <input type="radio" name="plan" value="{{ $plan->slug }}" class="sr-only" @checked(old('plan', $selectedPlan) === $plan->slug) required>
                            <span class="font-medium">{{ $plan->name }}</span><span class="text-xs opacity-70">{{ money($plan->monthly_price, $plan->currency) }}</span>
                        </label>
                    @endforeach
                </div>
            </div>
            <div class="grid gap-5 sm:grid-cols-2">
                <div><label class="label" for="salon_name">{{ __('platform.form.salon') }}</label>
                    <input id="salon_name" name="salon_name" class="input" x-model="name" @input="if (!touched) slug = name.toLowerCase().normalize('NFKD').replace(/[^\w\s-]/g, '').trim().replace(/[\s_]+/g, '-').slice(0, 40)" required></div>
                <div><label class="label" for="slug">{{ __('platform.signup.address') }}</label>
                    <div class="flex items-center rounded-(--radius-input) border border-line bg-white focus-within:border-gold" dir="ltr">
                        <input id="slug" name="slug" class="input min-w-0 flex-1 border-0 focus:ring-0" x-model="slug" @input="touched = true" pattern="[a-z0-9\-_]{3,40}">
                        <span class="pe-3 text-xs text-muted">.{{ config('salon.base_domain') }}</span>
                    </div></div>
                <div><label class="label" for="owner_name">{{ __('platform.form.name') }}</label><input id="owner_name" name="owner_name" class="input" value="{{ old('owner_name') }}" required autocomplete="name"></div>
                <div><label class="label" for="phone">{{ __('platform.form.phone') }}</label><input id="phone" name="phone" type="tel" class="input" value="{{ old('phone') }}" dir="ltr" autocomplete="tel"></div>
                <div><label class="label" for="email">{{ __('platform.form.email') }}</label><input id="email" name="email" type="email" class="input" value="{{ old('email') }}" required autocomplete="email"></div>
                <div><label class="label" for="password">{{ __('platform.signup.password') }}</label><input id="password" name="password" type="password" minlength="10" class="input" required autocomplete="new-password"></div>
                <div><label class="label" for="branches">{{ __('platform.form.branches') }}</label><input id="branches" name="branches" type="number" min="1" max="100" class="input" value="{{ old('branches', 1) }}"></div>
            </div>
            <div class="hidden" aria-hidden="true"><input name="website" tabindex="-1" autocomplete="off"></div>
            <label class="flex items-start gap-3 text-sm"><input type="checkbox" name="terms" value="1" required class="mt-1 accent-ink">
                <span>{!! __('platform.signup.terms', ['terms' => '<a class="underline" href="'.url('/legal/terms').'">'.e(__('platform.footer.terms')).'</a>', 'privacy' => '<a class="underline" href="'.url('/legal/privacy').'">'.e(__('platform.footer.privacy')).'</a>']) !!}</span></label>
            @if ($errors->any())<p class="rounded-xl bg-danger/10 px-4 py-3 text-sm text-danger" role="alert">{{ $errors->first() }}</p>@endif
            <button class="btn-primary w-full">{{ __('platform.signup.submit') }}</button>
            <p class="text-center text-xs text-muted">{{ __('platform.hero.trust') }}</p>
        </form>
    </section>
</x-layouts.platform>

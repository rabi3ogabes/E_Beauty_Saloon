<x-layouts.platform :title="__('platform.demo.title')" :description="__('platform.demo.meta')">
    <section class="container-luxe grid gap-12 py-16 md:grid-cols-[minmax(0,1fr)_1.1fr] md:py-24">
        <div>
            <p class="eyebrow">{{ __('platform.nav.demo') }}</p>
            <h1 class="mt-4 text-[clamp(2.2rem,5vw,4rem)] leading-[1.05] font-semibold">{{ __('platform.demo.title') }}</h1>
            <p class="mt-5 max-w-md text-lg text-muted">{{ __('platform.demo.body') }}</p>
            <ul class="mt-8 space-y-3 text-sm">
                @foreach (__('platform.demo.points') as $point)<li class="flex gap-3"><span class="text-gold-dark">✦</span>{{ $point }}</li>@endforeach
            </ul>
        </div>
        <form method="POST" action="{{ route('platform.demo.store') }}" class="card space-y-5 p-6 sm:p-8">
            @csrf
            @if (session('status'))<p class="rounded-xl bg-success/10 px-4 py-3 text-sm text-success" role="status">{{ session('status') }}</p>@endif
            <div class="grid gap-5 sm:grid-cols-2">
                <div><label class="label" for="name">{{ __('platform.form.name') }}</label><input id="name" name="name" class="input" value="{{ old('name') }}" required autocomplete="name"></div>
                <div><label class="label" for="salon_name">{{ __('platform.form.salon') }}</label><input id="salon_name" name="salon_name" class="input" value="{{ old('salon_name') }}" autocomplete="organization"></div>
                <div><label class="label" for="email">{{ __('platform.form.email') }}</label><input id="email" name="email" type="email" class="input" value="{{ old('email') }}" required autocomplete="email"></div>
                <div><label class="label" for="phone">{{ __('platform.form.phone') }}</label><input id="phone" name="phone" type="tel" class="input" value="{{ old('phone') }}" dir="ltr" autocomplete="tel"></div>
                <div><label class="label" for="branches">{{ __('platform.form.branches') }}</label><input id="branches" name="branches" type="number" min="1" class="input" value="{{ old('branches', 1) }}"></div>
                <div><label class="label" for="preferred_time">{{ __('platform.form.preferred_time') }}</label><input id="preferred_time" name="preferred_time" class="input" value="{{ old('preferred_time') }}" placeholder="{{ __('platform.form.preferred_time_hint') }}"></div>
            </div>
            <div><label class="label" for="message">{{ __('platform.form.message') }}</label><textarea id="message" name="message" rows="3" class="input py-3">{{ old('message') }}</textarea></div>
            <div class="hidden" aria-hidden="true"><input name="website" tabindex="-1" autocomplete="off"></div>
            @if ($errors->any())<p class="text-sm text-danger" role="alert">{{ $errors->first() }}</p>@endif
            <button class="btn-primary w-full">{{ __('platform.demo.submit') }}</button>
        </form>
    </section>
</x-layouts.platform>

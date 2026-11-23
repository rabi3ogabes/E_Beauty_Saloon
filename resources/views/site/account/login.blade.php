<x-layouts.site :title="__('account.sign_in')">
    <section class="container-luxe flex justify-center py-16 sm:py-24">
        <div class="card w-full max-w-md p-8 sm:p-10" x-data="{ step: @js($step) }">
            <p class="eyebrow">{{ tenant()->name }}</p>
            <h1 class="mt-3 text-3xl font-semibold">{{ __('account.sign_in') }}</h1>
            <p class="mt-2 text-sm text-muted">{{ __('account.sign_in_sub') }}</p>

            @if (session('status'))
                <p class="mt-6 rounded-xl bg-success/10 px-4 py-3 text-sm text-success" role="status">{{ session('status') }}</p>
            @endif
            @if (session('dev_otp'))
                <p class="mt-3 rounded-xl bg-gold/10 px-4 py-3 text-xs text-gold-dark">DEV code: <strong>{{ session('dev_otp') }}</strong></p>
            @endif

            <form x-show="step === 'phone'" method="POST" action="{{ route('client.login.send') }}" class="mt-8 space-y-5">
                @csrf
                <div>
                    <label for="phone" class="label">{{ __('account.phone') }}</label>
                    <input id="phone" name="phone" type="tel" inputmode="tel" autocomplete="tel" dir="ltr" required class="input" value="{{ $phone }}" placeholder="+974 5555 1234">
                    @error('phone')<p class="mt-2 text-sm text-danger">{{ $message }}</p>@enderror
                </div>
                <button class="btn-primary w-full">{{ __('account.send_code') }}</button>
                <p class="text-center text-xs text-muted">{{ __('account.whatsapp_note') }}</p>
            </form>

            <form x-show="step === 'code'" x-cloak method="POST" action="{{ route('client.login.verify') }}" class="mt-8 space-y-5">
                @csrf
                <input type="hidden" name="phone" value="{{ $phone }}">
                <p class="text-sm">{{ __('account.code_sent_to') }} <span dir="ltr" class="font-medium">{{ $phone }}</span>
                    <button type="button" class="ms-2 text-gold-dark underline underline-offset-4" @click="step = 'phone'">{{ __('account.change') }}</button></p>
                <div>
                    <label for="code" class="label">{{ __('account.code') }}</label>
                    <input id="code" name="code" inputmode="numeric" autocomplete="one-time-code" maxlength="6" pattern="\d{6}" required dir="ltr"
                           class="input text-center text-2xl tracking-[0.6em]" autofocus>
                    @error('code')<p class="mt-2 text-sm text-danger">{{ $message }}</p>@enderror
                </div>
                <div>
                    <label for="name" class="label">{{ __('account.your_name') }} <span class="text-muted">({{ __('salon.ui.optional') }})</span></label>
                    <input id="name" name="name" autocomplete="name" class="input">
                </div>
                <button class="btn-primary w-full">{{ __('account.verify') }}</button>
            </form>
        </div>
    </section>
</x-layouts.site>

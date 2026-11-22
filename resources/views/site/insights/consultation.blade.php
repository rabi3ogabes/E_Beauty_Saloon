<x-layouts.site :title="__('insights.consultation.title')">
    <section class="container-luxe max-w-2xl py-12 sm:py-16">
        <div class="text-center">
            <span class="mx-auto flex h-14 w-14 items-center justify-center rounded-full bg-gold/15 text-2xl text-gold-dark" aria-hidden="true">✦</span>
            <p class="eyebrow mt-5">{{ tenant()->name }}</p>
            <h1 class="mt-3 text-3xl font-semibold sm:text-4xl">{{ __('insights.consultation.title') }}</h1>
            <p class="mt-3 text-muted">{{ __('insights.consultation.intro', ['name' => $form->client?->firstName()]) }}</p>
        </div>

        @if ($form->isSigned())
            <div class="card mt-10 p-8 text-center">
                <p class="text-xl font-semibold">{{ session('status') ?: __('insights.consultation.thanks') }}</p>
                <p class="mt-2 text-muted">{{ __('insights.consultation.signed_on', ['date' => $form->signed_at->setTimezone(tenant()->timezone)->translatedFormat('j M Y H:i')]) }}</p>
            </div>
        @elseif ($form->isExpired())
            <div class="card mt-10 p-8 text-center"><p class="text-muted">{{ __('insights.consultation.expired') }}</p></div>
        @else
            <form method="POST" action="/consultation/{{ $form->token }}" class="card mt-10 space-y-6 p-6 sm:p-8"
                  x-data="signaturePad()" x-on:submit="capture($event)">
                @csrf
                @if ($errors->any())
                    <div class="rounded-[10px] border border-danger/30 bg-danger/5 p-3 text-sm text-danger" role="alert">{{ $errors->first() }}</div>
                @endif

                <fieldset>
                    <legend class="label">{{ __('insights.consultation.q.patch_test') }}</legend>
                    <div class="mt-2 flex flex-wrap gap-2">
                        @foreach (['yes', 'no', 'na'] as $v)
                            <label class="chip cursor-pointer has-[:checked]:chip-active">
                                <input type="radio" name="answers[patch_test]" value="{{ $v }}" class="sr-only" @checked(old('answers.patch_test') === $v)>
                                {{ __('insights.consultation.patch.'.$v) }}
                            </label>
                        @endforeach
                    </div>
                </fieldset>
                <label class="block">
                    <span class="label">{{ __('insights.consultation.q.patch_test_date') }}</span>
                    <input type="date" name="answers[patch_test_date]" value="{{ old('answers.patch_test_date') }}" class="input mt-1">
                </label>
                @foreach (['allergies', 'medications', 'skin_conditions', 'notes'] as $q)
                    <label class="block">
                        <span class="label">{{ __('insights.consultation.q.'.$q) }}</span>
                        <textarea name="answers[{{ $q }}]" rows="2" maxlength="1000" class="input mt-1">{{ old('answers.'.$q) }}</textarea>
                    </label>
                @endforeach
                <label class="flex min-h-11 items-center gap-3">
                    <input type="hidden" name="answers[pregnant]" value="0">
                    <input type="checkbox" name="answers[pregnant]" value="1" class="h-5 w-5 rounded" @checked(old('answers.pregnant'))>
                    <span>{{ __('insights.consultation.q.pregnant') }}</span>
                </label>

                <div class="hairline"></div>

                <label class="block">
                    <span class="label">{{ __('insights.consultation.signed_name') }}</span>
                    <input type="text" name="signed_name" required maxlength="120" value="{{ old('signed_name', $form->client?->name) }}" class="input mt-1">
                </label>
                <div>
                    <div class="flex items-center justify-between">
                        <span class="label">{{ __('insights.consultation.signature') }}</span>
                        <button type="button" class="text-sm text-muted underline-offset-2 hover:underline" x-on:click="clear()">{{ __('insights.consultation.clear') }}</button>
                    </div>
                    <canvas x-ref="canvas" class="mt-1 h-44 w-full touch-none rounded-[10px] border border-dashed border-gold/60 bg-white"
                            x-on:pointerdown="start($event)" x-on:pointermove="move($event)" x-on:pointerup="end()" x-on:pointerleave="end()"
                            aria-label="{{ __('insights.consultation.signature') }}"></canvas>
                    <p class="mt-1 text-xs text-muted">{{ __('insights.consultation.sign_hint') }}</p>
                    <input type="hidden" name="signature" x-ref="signature">
                </div>
                <label class="flex items-start gap-3">
                    <input type="checkbox" name="answers[consent]" value="1" required class="mt-1 h-5 w-5 rounded">
                    <span class="text-sm">{{ __('insights.consultation.consent', ['salon' => tenant()->name]) }}</span>
                </label>
                <button type="submit" class="btn-primary w-full">{{ __('insights.consultation.submit') }}</button>
            </form>
        @endif
    </section>

    <script>
        function signaturePad() {
            return {
                drawing: false, dirty: false, ctx: null,
                init() {
                    const c = this.$refs.canvas, ratio = window.devicePixelRatio || 1;
                    c.width = c.offsetWidth * ratio; c.height = c.offsetHeight * ratio;
                    this.ctx = c.getContext('2d'); this.ctx.scale(ratio, ratio);
                    this.ctx.lineWidth = 2.2; this.ctx.lineCap = 'round'; this.ctx.strokeStyle = '#1C1A17';
                },
                pos(e) { const r = this.$refs.canvas.getBoundingClientRect(); return [e.clientX - r.left, e.clientY - r.top]; },
                start(e) { this.drawing = true; const [x, y] = this.pos(e); this.ctx.beginPath(); this.ctx.moveTo(x, y); },
                move(e) { if (!this.drawing) return; const [x, y] = this.pos(e); this.ctx.lineTo(x, y); this.ctx.stroke(); this.dirty = true; },
                end() { this.drawing = false; },
                clear() { const c = this.$refs.canvas; this.ctx.clearRect(0, 0, c.width, c.height); this.dirty = false; },
                capture(e) {
                    if (!this.dirty) { e.preventDefault(); alert(@js(__('insights.consultation.signature_required'))); return; }
                    this.$refs.signature.value = this.$refs.canvas.toDataURL('image/png');
                },
            };
        }
    </script>
</x-layouts.site>

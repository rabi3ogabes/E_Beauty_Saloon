@php($main = $data->branches()->first())
<section id="contact" class="site-section">
    <div class="container-luxe">
        <div class="card grid gap-10 p-8 sm:p-14 md:grid-cols-[1.3fr_1fr]" data-reveal>
            <div>
                <h2 class="text-3xl font-semibold sm:text-4xl">{{ $section->t('title', __('website.sections.contact')) }}</h2>
                @if ($section->t('body'))<p class="mt-4 max-w-lg text-muted">{{ $section->t('body') }}</p>@endif
                <div class="mt-8 flex flex-wrap gap-3">
                    @if ($tenant->hasModule('booking'))<a href="{{ url('/book') }}" class="btn-primary">{{ __('site.cta.book_now') }}</a>@endif
                    @if ($wa = $main?->whatsapp)
                        <a href="https://wa.me/{{ preg_replace('/\D+/', '', $wa) }}" target="_blank" rel="noopener" class="btn-ghost">WhatsApp</a>
                    @endif
                    @if ($gbp = $tenant->setting('website.gbp_booking_url'))
                        <a href="{{ $gbp }}" target="_blank" rel="noopener" class="btn-ghost">Google</a>
                    @endif
                </div>
            </div>
            <dl class="space-y-5 text-sm">
                @if ($tenant->phone)<div><dt class="text-muted">{{ __('website.labels.phone') }}</dt><dd class="mt-1 text-base" dir="ltr"><a href="tel:{{ $tenant->phone }}">{{ $tenant->phone }}</a></dd></div>@endif
                @if ($tenant->email)<div><dt class="text-muted">{{ __('website.labels.email') }}</dt><dd class="mt-1 text-base"><a href="mailto:{{ $tenant->email }}">{{ $tenant->email }}</a></dd></div>@endif
                @if ($main)<div><dt class="text-muted">{{ __('website.labels.hours') }}</dt><dd class="mt-1 text-base">@include('site.website.partials.hours-today', ['branch' => $main])</dd></div>@endif
            </dl>
        </div>
    </div>
</section>

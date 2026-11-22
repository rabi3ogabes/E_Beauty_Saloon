<x-layouts.platform :title="__('platform.nav.blog')" :description="__('platform.blog.meta')">
    <section class="container-luxe pt-16 md:pt-20">
        <p class="eyebrow">{{ __('platform.nav.blog') }}</p>
        <h1 class="mt-4 text-[clamp(2.2rem,5vw,4rem)] leading-[1.05] font-semibold">{{ __('platform.blog.title') }}</h1>
        <p class="mt-4 max-w-xl text-lg text-muted">{{ __('platform.blog.subtitle') }}</p>
    </section>
    <section class="container-luxe py-12">
        @if ($posts->isEmpty())
            <div class="card p-12 text-center"><p class="font-display text-2xl">{{ __('platform.blog.empty') }}</p></div>
        @else
            <div class="grid gap-6 md:grid-cols-3">
                @foreach ($posts as $post)
                    @include('platform.blog.card', ['post' => $post])
                @endforeach
            </div>
            <div class="mt-10">{{ $posts->links() }}</div>
        @endif
    </section>
</x-layouts.platform>

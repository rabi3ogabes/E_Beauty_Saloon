<x-layouts.platform :title="$content['seo_title']" :description="$content['seo_description']" :og-image="$content['og_image']">
    @include('platform.partials.blocks')

    @php($posts = \App\Domain\Websites\Models\BlogPost::published()->limit(3)->get())
    @if ($posts->isNotEmpty())
        <section class="site-section">
            <div class="container-luxe">
                <div class="flex items-end justify-between gap-6">
                    <h2 class="text-3xl font-semibold sm:text-4xl">{{ __('platform.blog.latest') }}</h2>
                    <a href="{{ url('/blog') }}" class="btn-ghost">{{ __('platform.blog.all') }}</a>
                </div>
                <div class="mt-10 grid gap-6 md:grid-cols-3">
                    @foreach ($posts as $post)
                        @include('platform.blog.card', ['post' => $post])
                    @endforeach
                </div>
            </div>
        </section>
    @endif
</x-layouts.platform>

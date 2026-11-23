<a href="{{ url('/blog/'.$post->slug) }}" class="card-hover group flex flex-col overflow-hidden" data-reveal>
    <div class="aspect-[16/10] overflow-hidden bg-gradient-to-br from-rose via-ivory to-gold/30">
        @if ($post->cover_path)
            <x-site.picture :path="$post->cover_path" :alt="$post->title" class="h-full w-full object-cover transition duration-500 group-hover:scale-[1.03]" sizes="(min-width: 768px) 30vw, 90vw" />
        @endif
    </div>
    <div class="flex flex-1 flex-col p-6">
        <p class="text-xs text-muted">{{ $post->published_at?->translatedFormat('j M Y') }} · {{ trans_choice('platform.blog.minutes', $post->readingMinutes(), ['count' => $post->readingMinutes()]) }}</p>
        <h3 class="mt-2 text-xl font-semibold">{{ $post->title }}</h3>
        <p class="mt-2 flex-1 text-sm text-muted">{{ $post->excerpt }}</p>
    </div>
</a>

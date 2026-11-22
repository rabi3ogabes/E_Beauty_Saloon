@props(['path' => null, 'alt' => '', 'eager' => false, 'sizes' => '100vw', 'width' => null, 'height' => null])
@php
    // Responsive image: uses {name}-{w}.webp variants created on upload (ImageOptimizer) when present.
    $disk = \Illuminate\Support\Facades\Storage::disk('public');
    $isUrl = $path && preg_match('#^https?://#', $path);
    $src = $path ? ($isUrl ? $path : asset('storage/'.$path)) : null;
    $srcset = [];
    if ($path && ! $isUrl) {
        $base = preg_replace('/\.[a-z0-9]+$/i', '', $path);
        foreach (\App\Domain\Websites\Actions\OptimizeImage::WIDTHS as $w) {
            if ($disk->exists("{$base}-{$w}.webp")) {
                $srcset[] = asset("storage/{$base}-{$w}.webp")." {$w}w";
            }
        }
    }
@endphp
@if ($src)
    <img src="{{ $src }}" @if ($srcset) srcset="{{ implode(', ', $srcset) }}" sizes="{{ $sizes }}" @endif alt="{{ $alt }}"
         @if ($width) width="{{ $width }}" @endif @if ($height) height="{{ $height }}" @endif
         loading="{{ $eager ? 'eager' : 'lazy' }}" decoding="{{ $eager ? 'sync' : 'async' }}" @if ($eager) fetchpriority="high" @endif
         {{ $attributes }}>
@endif

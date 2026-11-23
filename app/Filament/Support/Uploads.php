<?php

namespace App\Filament\Support;

/** Upload allow-lists. Never accept SVG for images: it can carry script and uploads are served publicly. */
class Uploads
{
    public const IMAGE_TYPES = ['image/jpeg', 'image/png', 'image/webp', 'image/gif', 'image/avif'];
}

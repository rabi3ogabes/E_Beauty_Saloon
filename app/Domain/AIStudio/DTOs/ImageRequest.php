<?php

namespace App\Domain\AIStudio\DTOs;

/** Input for an image model call. Binaries are raw bytes (never persisted unencrypted). */
final class ImageRequest
{
    public function __construct(
        public readonly string $prompt,
        public readonly ?string $image = null,
        public readonly string $mime = 'image/jpeg',
        public readonly ?string $mask = null,
        public readonly string $aspect = '1:1',
        public readonly ?string $reference = null,
    ) {}
}

<?php

namespace App\Domain\AIStudio\DTOs;

/** Output of an image model call. status: succeeded|pending|failed (async providers return pending + externalId). */
final class ImageResult
{
    public function __construct(
        public readonly ?string $image,
        public readonly string $mime = 'image/png',
        public readonly string $status = 'succeeded',
        public readonly ?string $externalId = null,
        public readonly ?string $model = null,
    ) {}

    public function isPending(): bool
    {
        return $this->status === 'pending';
    }
}

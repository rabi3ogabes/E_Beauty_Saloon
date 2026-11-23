<?php

namespace App\Domain\AIStudio\Contracts;

use App\Domain\AIStudio\DTOs\ImageResult;

/** Reserved for short "look reveal" videos (Higgsfield). Not routed yet. */
interface AIVideoProvider
{
    public function animate(string $image, string $prompt): ImageResult;
}

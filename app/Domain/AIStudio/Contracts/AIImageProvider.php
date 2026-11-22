<?php

namespace App\Domain\AIStudio\Contracts;

use App\Domain\AIStudio\DTOs\ImageRequest;
use App\Domain\AIStudio\DTOs\ImageResult;

/** Image models (Gemini "Nano Banana", OpenAI images, Higgsfield...) — they edit/generate pixels. */
interface AIImageProvider
{
    public function editImage(ImageRequest $request): ImageResult;

    public function generateImage(ImageRequest $request): ImageResult;

    public function inpaint(ImageRequest $request): ImageResult;

    public function getStatus(string $externalId): ImageResult;
}

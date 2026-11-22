<?php

namespace App\Domain\AIStudio;

use App\Domain\AIStudio\Contracts\GeneratesMarketingContent;

class NullMarketingContentGenerator implements GeneratesMarketingContent
{
    public function bannerVisual(string $prompt, string $aspect = '1:1'): array
    {
        return ['image_url' => null, 'error' => __('salon.errors.module_disabled'), 'credits_used' => 0];
    }

    public function copy(string $brief, string $tone = 'luxury'): array
    {
        return ['en' => $brief, 'ar' => $brief];
    }
}

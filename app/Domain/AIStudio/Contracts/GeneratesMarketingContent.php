<?php

namespace App\Domain\AIStudio\Contracts;

/**
 * Hook used by Marketing (banner designer, campaign copy) to ask AI Studio for visuals / copy.
 * Default binding is a null implementation; the AI Studio module binds the real one (and charges credits).
 */
interface GeneratesMarketingContent
{
    /** @return array{image_url: ?string, error: ?string, credits_used: int} */
    public function bannerVisual(string $prompt, string $aspect = '1:1'): array;

    /** @return array{en: string, ar: string} */
    public function copy(string $brief, string $tone = 'luxury'): array;
}

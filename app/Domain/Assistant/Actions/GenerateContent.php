<?php

namespace App\Domain\Assistant\Actions;

use App\Domain\AIStudio\Providers\AbstractDriver;
use App\Domain\Catalog\Models\Service;
use App\Domain\Insights\Support\SmartText;
use App\Support\Exceptions\DomainException;

/**
 * Content assistant: service descriptions, offers and social captions in English AND Arabic in the salon's tone,
 * through the AI router; one charge per generation (task "content_assistant"). Review replies stay in Marketing.
 */
class GenerateContent
{
    public const KINDS = ['service_description', 'offer', 'caption'];

    public function __construct(protected SmartText $ai) {}

    /** @return array{en: string, ar: string, credits: int} */
    public function handle(string $kind, string $brief, string $tone = 'luxury', ?Service $service = null): array
    {
        abort_if(tenant()?->isReadOnly(), 423, __('salon.errors.read_only'));
        if (! in_array($kind, self::KINDS, true)) {
            throw new DomainException(__('insights.content.invalid_kind'));
        }
        if (! $this->ai->available('content_assistant')) {
            throw new DomainException(__('insights.content.unavailable'));
        }

        $subject = $service ? 'Service: '.$service->getTranslation('name', 'en', false).' ('.$service->duration_minutes.' min, '.money($service->price).'). ' : '';
        $what = match ($kind) {
            'service_description' => 'a service description for the salon website and booking page (2–3 sentences, sensory, no medical claims)',
            'offer' => 'a short promotional offer message (1–2 sentences with a clear call to book)',
            'caption' => 'an Instagram caption (1–2 sentences, up to 2 tasteful emojis, 3 relevant hashtags)',
        };
        $prompt = 'You write for "'.tenant()->name."\", a luxury beauty salon. Tone: {$tone}. Write {$what}. {$subject}Brief: ".trim($brief)
            .'. Write it once in English and once in elegant Gulf-friendly Arabic (not a literal translation). Reply ONLY with JSON {"en": "...", "ar": "..."}.';

        $reply = $this->ai->complete('content_assistant', $prompt);
        if (! $reply) {
            throw new DomainException(__('insights.content.unavailable'));
        }
        try {
            $json = AbstractDriver::extractJson($reply['text']);
        } catch (\Throwable) {
            $json = ['en' => trim($reply['text']), 'ar' => ''];
        }
        activity()->causedBy(auth()->user())->withProperties(['kind' => $kind, 'service_id' => $service?->id])->log('assistant.content_generated');

        return ['en' => trim((string) ($json['en'] ?? '')), 'ar' => trim((string) ($json['ar'] ?? '')), 'credits' => $reply['credits']];
    }
}

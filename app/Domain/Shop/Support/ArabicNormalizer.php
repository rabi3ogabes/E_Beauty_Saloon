<?php

namespace App\Domain\Shop\Support;

/**
 * Normalizes text for Arabic-aware search: strips tashkeel & tatweel, unifies alef/hamza forms,
 * teh marbuta, alef maqsura, Arabic-Indic digits, and lowercases Latin text.
 */
class ArabicNormalizer
{
    public static function normalize(?string $text): string
    {
        $text = (string) $text;
        // Tashkeel (harakat, tanween, shadda, sukun, dagger alef, Quranic marks) + tatweel.
        $text = preg_replace('/[\x{0610}-\x{061A}\x{064B}-\x{065F}\x{0670}\x{06D6}-\x{06ED}\x{0640}]/u', '', $text) ?? $text;
        $text = strtr($text, [
            'أ' => 'ا', 'إ' => 'ا', 'آ' => 'ا', 'ٱ' => 'ا',
            'ة' => 'ه', 'ى' => 'ي', 'ؤ' => 'و', 'ئ' => 'ي',
            '٠' => '0', '١' => '1', '٢' => '2', '٣' => '3', '٤' => '4', '٥' => '5', '٦' => '6', '٧' => '7', '٨' => '8', '٩' => '9',
        ]);
        $text = mb_strtolower($text);
        $text = preg_replace('/[^\p{L}\p{N}#\-\s]+/u', ' ', $text) ?? $text;

        return trim(preg_replace('/\s+/u', ' ', $text) ?? $text);
    }

    /** @return list<string> */
    public static function tokens(?string $query): array
    {
        return array_values(array_filter(explode(' ', self::normalize($query)), fn ($t) => mb_strlen($t) >= 1));
    }
}

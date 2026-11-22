<?php

namespace App\Domain\Catalog\Templates;

/**
 * Bilingual starter catalog for the onboarding "quick import" step.
 * Prices are QAR minor units (dirhams), typical of premium Doha salons; durations in minutes.
 */
final class ServiceTemplates
{
    public const CATEGORIES = [
        'hair' => ['en' => 'Hair', 'ar' => 'الشعر', 'icon' => 'heroicon-o-scissors'],
        'nails' => ['en' => 'Nails', 'ar' => 'الأظافر', 'icon' => 'heroicon-o-hand-raised'],
        'lashes' => ['en' => 'Lashes', 'ar' => 'الرموش', 'icon' => 'heroicon-o-eye'],
        'brows' => ['en' => 'Brows', 'ar' => 'الحواجب', 'icon' => 'heroicon-o-face-smile'],
        'makeup' => ['en' => 'Makeup', 'ar' => 'المكياج', 'icon' => 'heroicon-o-sparkles'],
        'spa' => ['en' => 'Spa & Body', 'ar' => 'السبا والجسم', 'icon' => 'heroicon-o-sun'],
    ];

    /** @return array<string, list<array{en:string, ar:string, duration:int, price:int, rebook?:int, deposit?:int, resources?:list<string>}>> */
    public static function all(): array
    {
        return [
            'hair' => [
                ['en' => 'Signature Haircut & Blow-dry', 'ar' => 'قص وتصفيف مميز', 'duration' => 60, 'price' => 18000, 'rebook' => 42, 'resources' => ['chair']],
                ['en' => 'Blow-dry & Styling', 'ar' => 'سشوار وتصفيف', 'duration' => 45, 'price' => 12000, 'resources' => ['chair']],
                ['en' => 'Root Colour', 'ar' => 'صبغة الجذور', 'duration' => 90, 'price' => 35000, 'rebook' => 35, 'resources' => ['chair', 'wash_basin']],
                ['en' => 'Balayage', 'ar' => 'بالياج', 'duration' => 180, 'price' => 95000, 'rebook' => 90, 'deposit' => 2000, 'resources' => ['chair', 'wash_basin']],
                ['en' => 'Keratin Smoothing Treatment', 'ar' => 'علاج الكيراتين', 'duration' => 150, 'price' => 120000, 'rebook' => 120, 'deposit' => 2000, 'resources' => ['chair']],
                ['en' => 'Olaplex Bond Treatment', 'ar' => 'علاج أولابلكس', 'duration' => 45, 'price' => 25000, 'resources' => ['wash_basin']],
                ['en' => 'Bridal Hair Updo', 'ar' => 'تسريحة عروس', 'duration' => 120, 'price' => 150000, 'deposit' => 3000, 'resources' => ['chair']],
            ],
            'nails' => [
                ['en' => 'Classic Manicure', 'ar' => 'مانيكير كلاسيكي', 'duration' => 45, 'price' => 9000, 'rebook' => 14],
                ['en' => 'Gel Manicure', 'ar' => 'مانيكير جل', 'duration' => 60, 'price' => 15000, 'rebook' => 21],
                ['en' => 'Classic Pedicure', 'ar' => 'باديكير كلاسيكي', 'duration' => 60, 'price' => 12000, 'rebook' => 21, 'resources' => ['pedicure_station']],
                ['en' => 'Spa Pedicure', 'ar' => 'باديكير سبا', 'duration' => 75, 'price' => 18000, 'rebook' => 28, 'resources' => ['pedicure_station']],
                ['en' => 'Acrylic Full Set', 'ar' => 'تركيب أظافر أكريليك', 'duration' => 90, 'price' => 25000, 'rebook' => 21],
                ['en' => 'Nail Art (per nail)', 'ar' => 'رسم على الأظافر (للظفر)', 'duration' => 10, 'price' => 1500],
            ],
            'lashes' => [
                ['en' => 'Classic Lash Extensions', 'ar' => 'رموش كلاسيكية', 'duration' => 120, 'price' => 35000, 'rebook' => 21, 'deposit' => 2000, 'resources' => ['bed']],
                ['en' => 'Volume Lash Extensions', 'ar' => 'رموش فوليوم', 'duration' => 150, 'price' => 45000, 'rebook' => 21, 'deposit' => 2000, 'resources' => ['bed']],
                ['en' => 'Lash Lift & Tint', 'ar' => 'رفع وصبغ الرموش', 'duration' => 60, 'price' => 22000, 'rebook' => 42, 'resources' => ['bed']],
                ['en' => 'Lash Refill', 'ar' => 'تعبئة الرموش', 'duration' => 75, 'price' => 20000, 'rebook' => 21, 'resources' => ['bed']],
            ],
            'brows' => [
                ['en' => 'Brow Shaping (Threading)', 'ar' => 'تشكيل الحواجب بالخيط', 'duration' => 20, 'price' => 6000, 'rebook' => 21],
                ['en' => 'Brow Lamination', 'ar' => 'لامينيشن الحواجب', 'duration' => 45, 'price' => 20000, 'rebook' => 42],
                ['en' => 'Henna Brows', 'ar' => 'حواجب بالحناء', 'duration' => 40, 'price' => 15000, 'rebook' => 28],
                ['en' => 'Microblading', 'ar' => 'مايكروبليدنغ', 'duration' => 150, 'price' => 150000, 'deposit' => 3000, 'resources' => ['bed']],
            ],
            'makeup' => [
                ['en' => 'Soft Glam Makeup', 'ar' => 'مكياج سوفت جلام', 'duration' => 60, 'price' => 40000, 'resources' => ['chair']],
                ['en' => 'Evening Makeup', 'ar' => 'مكياج سهرة', 'duration' => 75, 'price' => 55000, 'resources' => ['chair']],
                ['en' => 'Bridal Makeup', 'ar' => 'مكياج عروس', 'duration' => 120, 'price' => 250000, 'deposit' => 3000, 'resources' => ['chair']],
                ['en' => 'Makeup Lesson', 'ar' => 'درس مكياج', 'duration' => 90, 'price' => 60000, 'resources' => ['chair']],
            ],
            'spa' => [
                ['en' => 'Moroccan Bath', 'ar' => 'حمام مغربي', 'duration' => 75, 'price' => 35000, 'rebook' => 30, 'resources' => ['room']],
                ['en' => 'Hydrating Facial', 'ar' => 'تنظيف بشرة مرطب', 'duration' => 60, 'price' => 38000, 'rebook' => 30, 'resources' => ['bed']],
                ['en' => 'Hot Stone Massage', 'ar' => 'مساج بالأحجار الساخنة', 'duration' => 90, 'price' => 48000, 'resources' => ['room']],
                ['en' => 'Full Body Waxing', 'ar' => 'إزالة الشعر بالشمع للجسم', 'duration' => 90, 'price' => 42000, 'rebook' => 35, 'resources' => ['bed']],
                ['en' => 'Body Scrub & Wrap', 'ar' => 'تقشير ولف الجسم', 'duration' => 60, 'price' => 30000, 'resources' => ['room']],
            ],
        ];
    }

    public static function keys(): array
    {
        return array_keys(self::CATEGORIES);
    }
}

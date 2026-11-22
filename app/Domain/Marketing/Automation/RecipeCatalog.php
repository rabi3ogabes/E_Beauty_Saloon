<?php

namespace App\Domain\Marketing\Automation;

/**
 * Prebuilt automation recipes. Installed per tenant by InstallAutomationRecipes (owners can edit every step).
 * booking_reminders is disabled by default: the Booking module already sends transactional reminders.
 */
final class RecipeCatalog
{
    public static function all(): array
    {
        return [
            'welcome_series' => [
                'trigger' => 'client_created', 'active' => true, 'config' => [],
                'steps' => [
                    ['type' => 'wait', 'hours' => 1],
                    ['type' => 'send_message', 'channels' => ['whatsapp', 'sms', 'email'],
                        'body' => ['en' => 'Welcome to {salon}, {first_name} ✦ We are delighted to have you. Book your first ritual whenever you are ready: {link}', 'ar' => 'أهلاً بكِ في {salon} يا {first_name} ✦ يسعدنا انضمامك. احجزي أول جلسة متى شئتِ: {link}'],
                        'subject' => ['en' => 'Welcome to {salon}', 'ar' => 'أهلاً بكِ في {salon}']],
                    ['type' => 'wait', 'days' => 3],
                    ['type' => 'condition', 'check' => 'no_booking_since_start'],
                    ['type' => 'coupon', 'discount_bp' => 1000, 'valid_days' => 14],
                    ['type' => 'send_message', 'channels' => ['whatsapp', 'sms', 'email'],
                        'body' => ['en' => 'A little welcome gift, {first_name}: 10% off your first visit with code {code}. {link}', 'ar' => 'هدية ترحيب صغيرة يا {first_name}: خصم 10% على زيارتك الأولى بالرمز {code}. {link}']],
                ],
            ],
            'booking_reminders' => [
                'trigger' => 'booking_upcoming', 'active' => false, 'config' => ['hours_before' => 24],
                'steps' => [
                    ['type' => 'send_message', 'channels' => ['whatsapp', 'sms'], 'transactional' => true,
                        'body' => ['en' => 'Hi {first_name}, a gentle reminder of your appointment at {salon} on {when}. We look forward to seeing you.', 'ar' => 'مرحباً {first_name}، تذكير لطيف بموعدك في {salon} يوم {when}. بانتظارك.']],
                ],
            ],
            'review_request' => [
                'trigger' => 'booking_completed', 'active' => true, 'config' => [],
                'steps' => [
                    ['type' => 'wait', 'hours' => 2],
                    ['type' => 'send_message', 'channels' => ['whatsapp', 'sms', 'email'], 'link' => '/review/{reference}',
                        'body' => ['en' => 'Thank you for visiting {salon}, {first_name}. How was your experience? It takes 10 seconds: {review_url}', 'ar' => 'شكراً لزيارتك {salon} يا {first_name}. كيف كانت تجربتك؟ يستغرق الأمر 10 ثوانٍ: {review_url}']],
                ],
            ],
            'rebook_reminder' => [
                'trigger' => 'rebook_due', 'active' => true, 'config' => [],
                'steps' => [
                    ['type' => 'send_message', 'channels' => ['whatsapp', 'sms', 'email'],
                        'body' => ['en' => 'Hi {first_name}, it is about time for your next {service} ✦ Reserve your favourite slot: {link}', 'ar' => 'مرحباً {first_name}، حان موعد {service} القادم ✦ احجزي موعدك المفضل: {link}']],
                ],
            ],
            'win_back' => [
                'trigger' => 'win_back', 'active' => true, 'config' => ['days' => 60],
                'steps' => [
                    ['type' => 'coupon', 'discount_bp' => 1500, 'valid_days' => 21],
                    ['type' => 'send_message', 'channels' => ['whatsapp', 'sms', 'email'],
                        'body' => ['en' => 'We miss you, {first_name}. Enjoy 15% off your next visit with code {code} — valid for 3 weeks. {link}', 'ar' => 'اشتقنا إليكِ يا {first_name}. استمتعي بخصم 15% على زيارتك القادمة بالرمز {code} — صالح لمدة 3 أسابيع. {link}']],
                    ['type' => 'wait', 'days' => 10],
                    ['type' => 'condition', 'check' => 'not_visited_since_start'],
                    ['type' => 'send_message', 'channels' => ['whatsapp', 'email'],
                        'body' => ['en' => 'Your 15% welcome-back code {code} is waiting, {first_name}. {link}', 'ar' => 'رمز الخصم {code} لا يزال بانتظارك يا {first_name}. {link}']],
                ],
            ],
            'birthday' => [
                'trigger' => 'birthday', 'active' => true, 'config' => [],
                'steps' => [
                    ['type' => 'coupon', 'discount_bp' => 2000, 'valid_days' => 30],
                    ['type' => 'award_points', 'points' => 200],
                    ['type' => 'send_message', 'channels' => ['whatsapp', 'sms', 'email'],
                        'body' => ['en' => 'Happy birthday, {first_name}! ✦ A gift from all of us at {salon}: 20% off any ritual this month with code {code}. {link}', 'ar' => 'عيد ميلاد سعيد يا {first_name}! ✦ هدية من فريق {salon}: خصم 20% على أي جلسة هذا الشهر بالرمز {code}. {link}'],
                        'subject' => ['en' => 'A birthday gift for you', 'ar' => 'هدية عيد ميلادك']],
                ],
            ],
            'abandoned_cart' => [
                'trigger' => 'abandoned_cart', 'active' => true, 'config' => [],
                'steps' => [
                    ['type' => 'wait', 'hours' => 1],
                    ['type' => 'send_message', 'channels' => ['whatsapp', 'email', 'push'], 'link' => '/shop/cart',
                        'body' => ['en' => 'You left something beautiful behind, {first_name}. Your bag is saved: {cart_url}', 'ar' => 'تركتِ شيئاً جميلاً يا {first_name}. سلتك محفوظة: {cart_url}']],
                ],
            ],
            'low_wallet' => [
                'trigger' => 'low_wallet', 'active' => true, 'config' => ['below' => 50],
                'steps' => [
                    ['type' => 'send_message', 'channels' => ['whatsapp', 'sms', 'email'], 'transactional' => true,
                        'body' => ['en' => 'Hi {first_name}, your {salon} wallet balance is {balance}. Top up at your next visit to keep enjoying member pricing.', 'ar' => 'مرحباً {first_name}، رصيد محفظتك في {salon} هو {balance}. اشحني رصيدك في زيارتك القادمة.']],
                ],
            ],
            'package_expiring' => [
                'trigger' => 'package_expiring', 'active' => true, 'config' => ['days_before' => 14],
                'steps' => [
                    ['type' => 'send_message', 'channels' => ['whatsapp', 'sms', 'email'], 'transactional' => true,
                        'body' => ['en' => 'Hi {first_name}, you still have {sessions} session(s) of {package}, valid until {expires}. Book now: {link}', 'ar' => 'مرحباً {first_name}، لديكِ {sessions} جلسة متبقية من {package} صالحة حتى {expires}. احجزي الآن: {link}']],
                ],
            ],
            'membership_renewal' => [
                'trigger' => 'membership_renewal', 'active' => true, 'config' => ['days_before' => 3],
                'steps' => [
                    ['type' => 'send_message', 'channels' => ['whatsapp', 'sms', 'email'], 'transactional' => true,
                        'body' => ['en' => 'Hi {first_name}, your {membership} renews on {renews}. Thank you for being part of our beauty club ✦', 'ar' => 'مرحباً {first_name}، يتجدد اشتراكك في {membership} يوم {renews}. شكراً لكونك جزءاً من نادينا ✦']],
                ],
            ],
        ];
    }
}

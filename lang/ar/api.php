<?php

return [
    'errors' => [
        'unauthenticated' => 'يرجى تسجيل الدخول مرة أخرى.',
        'wrong_app' => 'تسجيل الدخول هذا غير صالح لهذا التطبيق.',
        'forbidden' => 'لا تملكين صلاحية الوصول إلى هذا.',
        'not_found' => 'غير موجود.',
        'method_not_allowed' => 'هذا الإجراء غير مدعوم.',
        'conflict' => 'يتعارض هذا الطلب مع الحالة الحالية. يرجى التحديث.',
        'read_only' => 'حساب الصالون للقراءة فقط حالياً.',
        'too_many_requests' => 'محاولات كثيرة. يرجى الانتظار قليلاً.',
        'server_error' => 'حدث خطأ من جهتنا. يرجى المحاولة مرة أخرى.',
        'validation_failed' => 'يرجى مراجعة الحقول المحددة.',
        'domain_error' => 'تعذّر إتمام هذا الطلب.',
        'module_disabled' => 'هذه الميزة غير متاحة لهذا الصالون.',
        'error' => 'حدث خطأ ما.',
        'idempotency_key_invalid' => 'ترويسة Idempotency-Key طويلة جداً (100 حرف كحد أقصى).',
        'idempotency_in_progress' => 'الطلب نفسه قيد المعالجة. يرجى إعادة المحاولة بعد لحظة.',
        'idempotency_mismatch' => 'تم استخدام مفتاح Idempotency-Key هذا لطلب مختلف.',
    ],

    'booking' => [
        'cannot_cancel' => 'لم يعد بالإمكان إلغاء هذا الحجز.',
    ],

    'auth' => [
        'invalid_credentials' => 'بيانات الدخول غير صحيحة.',
        'not_allowed' => 'لا يمكن لحسابك استخدام هذا التطبيق. اطلبي الصلاحية من مالكة الصالون.',
        'mfa_required' => 'أدخلي الرمز المكوّن من 6 أرقام من تطبيق المصادقة.',
        'mfa_invalid' => 'رمز المصادقة غير صالح.',
        'inactive' => 'حسابك معطّل.',
    ],

    'studio' => [
        'consent_required' => 'يرجى الموافقة على شروط معالجة الصور بالذكاء الاصطناعي أولاً.',
        'style_category_mismatch' => 'هذا الستايل لا ينتمي إلى الفئة المختارة.',
        'look_not_saved' => 'يمكن حفظ الإطلالات المكتملة فقط.',
    ],

    'packages' => [
        'checkout_required' => 'أكملي الدفع لتفعيل باقتك.',
    ],

    'devices' => [
        'registered' => 'تم تسجيل الجهاز لتلقي الإشعارات.',
    ],

    'staff' => [
        'no_profile' => 'حسابك غير مرتبط بملف خبيرة.',
        'not_your_booking' => 'يمكنك تسجيل وصول مواعيدك فقط.',
        'time_off_requested' => 'تم طلب الإجازة. سيراجعها الصالون.',
        'time_off_overlaps' => 'لديك إجازة بالفعل في هذه الفترة.',
        'checked_in_from_app' => 'تم تسجيل الحضور من تطبيق الفريق بواسطة :name',
        'commission' => 'عمولة',
    ],

    'owner' => [
        'campaign_queued' => 'الحملة في طريقها إلى :count عميلة.',
        'quick_campaign' => 'حملة سريعة :date',
        'alerts' => [
            'low_stock' => 'مخزون منخفض: :count منتجات',
            'low_stock_body' => ':items',
            'time_off' => 'طلبات إجازة بانتظار المراجعة: :count',
            'time_off_body' => ':names',
            'failed_payments' => 'مدفوعات فاشلة (24 ساعة): :count',
            'failed_payments_body' => 'مدفوعات إلكترونية لم تكتمل خلال آخر 24 ساعة.',
            'ai_credits' => 'رصيد استوديو الذكاء الاصطناعي منخفض (متبقٍ :balance)',
            'ai_credits_body' => 'اشحني الرصيد لتواصل العميلات تجربة الإطلالات.',
            'message_credits' => 'رصيد الرسائل منخفض (متبقٍ :balance)',
            'message_credits_body' => 'اشحني الرصيد قبل إرسال الحملة التالية.',
            'bad_review' => 'تقييم :rating★ من :client',
            'bad_review_body' => ':comment',
        ],
    ],

    'notifications' => [
        'campaign' => 'رسالة من :salon',
    ],
];

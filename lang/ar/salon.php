<?php

return [
    'tenant_status' => ['trial' => 'تجربة', 'active' => 'نشط', 'grace' => 'فترة سماح', 'suspended' => 'موقوف', 'cancelled' => 'ملغى'],
    'subscription_status' => ['pending' => 'بانتظار الدفع', 'trialing' => 'تجربة', 'active' => 'نشط', 'past_due' => 'متأخر', 'grace' => 'سماح', 'suspended' => 'موقوف', 'cancelled' => 'ملغى', 'expired' => 'منتهي'],
    'invoice_status' => ['draft' => 'مسودة', 'open' => 'مستحقة', 'paid' => 'مدفوعة', 'void' => 'ملغاة', 'refunded' => 'مستردة'],

    'nav' => [
        'salons' => 'الصالونات', 'billing' => 'الفوترة', 'growth' => 'النمو', 'ai' => 'الذكاء الاصطناعي', 'website' => 'الموقع', 'platform' => 'المنصة',
        'today' => 'اليوم', 'bookings' => 'الحجوزات', 'sales' => 'المبيعات', 'clients' => 'العملاء', 'catalog' => 'الخدمات والمنتجات', 'inventory' => 'المخزون',
        'shop' => 'المتجر', 'marketing' => 'التسويق', 'ai_studio' => 'استوديو الجمال الذكي', 'team' => 'الفريق', 'reports' => 'التقارير', 'settings' => 'الإعدادات',
    ],

    'ui' => [
        'language' => 'اللغة', 'branch' => 'الفرع', 'all_branches' => 'كل الفروع', 'save' => 'حفظ', 'cancel' => 'إلغاء', 'continue' => 'متابعة',
        'back' => 'رجوع', 'search' => 'بحث', 'english' => 'الإنجليزية', 'arabic' => 'العربية', 'name' => 'الاسم', 'status' => 'الحالة', 'actions' => 'إجراءات',
        'created' => 'تاريخ الإنشاء', 'copy' => 'نسخ', 'copied' => 'تم النسخ', 'view' => 'عرض', 'edit' => 'تعديل', 'delete' => 'حذف', 'none' => 'لا يوجد',
        'yes' => 'نعم', 'no' => 'لا', 'optional' => 'اختياري', 'minutes' => 'د', 'per_month' => '/ شهرياً', 'learn_more' => 'اعرف المزيد',
    ],

    'modules' => [
        'booking' => 'الحجز الإلكتروني', 'pos' => 'نقطة البيع', 'shop' => 'المتجر الإلكتروني', 'marketing' => 'التسويق وإدارة العملاء',
        'ai_studio' => 'استوديو الجمال الذكي', 'website' => 'موقع فاخر', 'loyalty' => 'الولاء والإحالات',
    ],

    'billing' => [
        'line_plan' => 'باقة :plan × :months شهر',
        'line_extra_branches' => ':count فرع إضافي × :months شهر',
        'line_module_branch' => ':module · :branches فرع × :months شهر',
        'line_module' => ':module × :months شهر',
        'line_cycle_discount' => 'خصم مدة الاشتراك (:percent%)',
        'line_coupon' => 'كوبون :code',
        'line_proration' => 'فرق التغيير للأيام المتبقية (:days يوم)',
        'line_credit' => 'رصيد من تغيير الباقة السابق',
        'reminder_subject' => 'يتجدد اشتراكك خلال :days يوم',
        'hello' => 'مرحباً :name،',
        'reminder_line' => 'يتجدد اشتراك صالونك خلال :days يوم بتاريخ :date.',
        'amount_due' => 'المبلغ المستحق: :amount (فاتورة :number).',
        'pay_now' => 'ادفع الآن',
        'suspended_subject' => 'تم إيقاف لوحة تحكم صالونك مؤقتاً',
        'suspended_line' => 'لم نتمكن من تجديد اشتراكك. يعرض موقعك صفحة "غير متاح مؤقتاً" حتى يتم الدفع.',
        'reactivate' => 'إعادة التفعيل',
        'grace_banner' => 'انتهى اشتراكك. لوحة التحكم للقراءة فقط حتى :date.',
        'subtotal' => 'المجموع الفرعي', 'discount' => 'الخصم', 'tax' => 'الضريبة', 'total' => 'الإجمالي', 'per_month_equiv' => '≈ :amount شهرياً',
        'cycle' => '{1} شهري|[2,*] كل :count أشهر',
    ],

    'payments' => [
        'missing_credentials' => 'يرجى تعبئة جميع بيانات الربط أولاً.',
        'test_ok' => 'تم الاتصال بنجاح.',
        'test_unreachable' => 'مزود الدفع غير متاح حالياً.',
        'test_failed' => 'رفض مزود الدفع هذه البيانات.',
        'methods' => ['cash' => 'نقداً', 'card_terminal' => 'جهاز البطاقة', 'online' => 'دفع إلكتروني', 'wallet' => 'المحفظة', 'gift_card' => 'بطاقة هدية', 'points' => 'نقاط الولاء', 'bank_transfer' => 'تحويل بنكي', 'cod' => 'الدفع عند الاستلام'],
        'drivers' => ['sadad' => 'سداد', 'skipcash' => 'سكيب كاش', 'myfatoorah' => 'ماي فاتورة', 'tap' => 'تاب', 'stripe' => 'سترايب', 'cash' => 'نقداً', 'card_terminal' => 'جهاز البطاقة', 'bank_transfer' => 'تحويل بنكي', 'cod' => 'الدفع عند الاستلام'],
        'success_title' => 'تم استلام الدفعة', 'success_body' => 'شكراً لك — تم تأكيد الدفع.',
        'pending_title' => 'جارٍ تأكيد الدفع', 'pending_body' => 'يستغرق ذلك عادة ثوانٍ قليلة. يمكنك إغلاق الصفحة بأمان.',
        'failed_title' => 'لم يكتمل الدفع', 'failed_body' => 'لم يتم خصم أي مبلغ. حاول مجدداً أو اختر وسيلة أخرى.',
    ],

    'impersonation' => [
        'expired' => 'انتهت صلاحية رابط الدخول. يرجى البدء من جديد من لوحة الإدارة.',
        'banner' => 'أنت تتصفح بصفتك :owner (جلسة دعم بواسطة :admin)',
        'exit' => 'خروج',
        'reason' => 'سبب الدخول',
        'login_as_owner' => 'الدخول كمالك',
    ],

    'errors' => [
        'unsafe_url' => 'هذا العنوان غير مسموح. استخدم رابط https:// عاماً.',
        'insufficient_stock' => 'المخزون غير كافٍ لـ :item (المتوفر: :available).',
        'same_branch_transfer' => 'اختر فرعين مختلفين للتحويل.',
        'insufficient_wallet' => 'رصيد المحفظة غير كافٍ (الرصيد: :balance).',
        'gift_card_invalid' => 'بطاقة الهدية غير صالحة.',
        'gift_card_balance' => 'رصيد بطاقة الهدية غير كافٍ (الرصيد: :balance).',
        'insufficient_points' => 'نقاط الولاء غير كافية (الرصيد: :points).',
        'invalid_cycle' => 'يرجى اختيار مدة 1 أو 3 أو 6 أو 12 شهراً.',
        'unknown_module' => 'وحدة غير معروفة: :module.',
        'coupon_invalid' => 'الكوبون غير صالح أو منتهي.',
        'slug_reserved' => 'هذا العنوان محجوز. يرجى اختيار عنوان آخر.',
        'email_taken' => 'يوجد حساب بهذا البريد الإلكتروني.',
        'domain_invalid' => 'يرجى إدخال نطاق صحيح مثل book.mysalon.com.',
        'domain_reserved' => 'لا يمكن استخدام هذا النطاق.',
        'domain_taken' => 'هذا النطاق مرتبط بصالون آخر.',
        'no_owner' => 'لا يوجد حساب مالك لهذا الصالون.',
        'read_only' => 'انتهى اشتراكك — لوحة التحكم للقراءة فقط حتى التجديد.',
        'module_disabled' => 'هذه الميزة غير متضمنة في باقتك الحالية.',
        'refund_amount' => 'مبلغ الاسترداد غير صحيح.',
        'refund_needs_client' => 'رصيد المتجر يتطلب ربط عميل بعملية البيع.',
        'gateway_refund_failed' => 'تعذر على مزود الدفع تنفيذ الاسترداد.',
        'branch_limit' => 'تتضمن باقتك :limit فرع. قم بالترقية لإضافة المزيد.',
    ],

    'unavailable' => [
        'title' => 'سنعود قريباً',
        'body' => ':salon غير متاح إلكترونياً مؤقتاً. يرجى التواصل معنا مباشرة للحجز.',
    ],
];

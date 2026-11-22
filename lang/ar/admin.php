<?php

return [
    'all' => 'الكل', 'days' => 'يوم', 'never' => 'أبداً', 'last_login' => 'آخر دخول',
    'cycle' => [1 => 'شهري', 3 => '3 أشهر', 6 => '6 أشهر', 12 => 'سنوي'],
    'dashboard' => [
        'greeting' => 'سعداء برؤيتك، :name',
        'arr' => 'الإيراد السنوي :amount', 'active_salons' => 'الصالونات النشطة', 'trials' => ':count في التجربة', 'churn' => 'معدل الإلغاء',
        'last_30' => 'آخر 30 يوماً', 'renewals' => 'التجديدات خلال 7 أيام', 'overdue' => ':count فواتير متأخرة',
        'revenue_by_module' => 'الإيراد الشهري حسب الوحدة', 'collected' => 'الإيرادات المحصّلة — آخر 12 شهراً',
        'plan' => 'الباقات الأساسية', 'branches' => 'الفروع الإضافية', 'no_renewals' => 'لا توجد تجديدات قريبة', 'upcoming_renewals' => 'التجديدات القادمة — خلال 14 يوماً',
    ],
    'tenant' => [
        'label' => 'صالون', 'plural' => 'الصالونات', 'new' => 'صالون جديد', 'trials_badge' => 'صالونات في التجربة',
        'business' => 'بيانات النشاط', 'legal_name' => 'الاسم القانوني', 'tax_number' => 'الرقم الضريبي', 'overview' => 'نظرة عامة',
        'subscription' => 'الاشتراك', 'no_plan' => 'لا توجد باقة نشطة', 'subscriptions' => 'الاشتراكات', 'team' => 'حسابات الفريق',
        'empty' => 'لا توجد صالونات بعد', 'empty_body' => 'أنشئ أول حساب صالون للبدء.',
        'step_salon' => 'الصالون والمالك', 'step_plan' => 'الباقة والوحدات', 'extra_modules' => 'وحدات إضافية',
        'included' => 'متضمن في الباقة: :modules', 'quote' => 'تفاصيل السعر', 'quote_hint' => 'اختر باقة ومدة اشتراك لعرض السعر.',
        'created' => ':salon جاهز.',
        'impersonate_heading' => 'فتح :salon بصفة المالك',
        'impersonate_body' => 'تُفتح جلسة دعم في تبويب جديد مع شريط ذهبي. يتم تسجيلها وتظهر للمالك في سجل الأمان.',
        'impersonate_placeholder' => 'مثال: طلب المالك المساعدة في إعداد سداد',
        'extend_trial' => 'تمديد التجربة', 'trial_extended' => 'تم تمديد التجربة حتى :date',
        'suspend' => 'إيقاف', 'suspend_body' => 'تُقفل لوحة التحكم ويعرض الموقع "غير متاح مؤقتاً".', 'suspended' => 'تم إيقاف الصالون',
        'reactivate' => 'إعادة التفعيل', 'reactivated' => 'تمت إعادة تفعيل الصالون',
        'change_plan' => 'تغيير الباقة', 'prorated_charge' => 'ستصدر فاتورة فرق بقيمة :amount لباقي المدة.',
        'prorated_credit' => 'سيُطبق رصيد بقيمة :amount على التجديد القادم.',
        'proration_invoiced' => 'صدرت فاتورة الفرق :number — يُطبق التغيير بعد الدفع.', 'plan_changed' => 'تم تحديث الباقة',
    ],
    'invoice' => [
        'label' => 'فاتورة', 'plural' => 'الفواتير', 'mark_paid' => 'تسجيل دفعة', 'paid_ok' => 'تم تسجيل الدفع للفاتورة :number',
        'paid' => 'المدفوع', 'items' => 'البنود', 'tax_invoice' => 'فاتورة ضريبية', 'billed_to' => 'فاتورة إلى', 'qty' => 'الكمية', 'unit' => 'سعر الوحدة',
        'footer' => 'شكراً لنموك مع :platform.',
        'types' => ['subscription' => 'اشتراك', 'renewal' => 'تجديد', 'proration' => 'فرق تغيير', 'credits' => 'رصيد', 'addon' => 'إضافة'],
    ],
    'payment' => [
        'label' => 'دفعة', 'plural' => 'المدفوعات', 'proof' => 'الإثبات', 'approve' => 'اعتماد', 'reject' => 'رفض', 'approved' => 'تم اعتماد التحويل — الفاتورة مدفوعة',
        'status' => ['pending' => 'قيد الانتظار', 'awaiting_approval' => 'بانتظار الاعتماد', 'succeeded' => 'ناجحة', 'failed' => 'فاشلة', 'superseded' => 'مطبّقة', 'refunded' => 'مستردة'],
    ],
    'coupon' => [
        'label' => 'كوبون', 'plural' => 'الكوبونات', 'percent' => 'نسبة', 'fixed' => 'مبلغ ثابت', 'first_cycle' => 'الدورة الأولى', 'forever' => 'كل دورة',
        'value_help' => 'نسبة (مثل 20) أو مبلغ (مثل 50.00).',
    ],
    'module' => ['label' => 'وحدة', 'plural' => 'الوحدات', 'per_account' => 'لكل حساب', 'per_branch' => 'لكل فرع', 'prices' => 'الأسعار', 'any_plan' => 'أي باقة'],
    'plan' => [
        'label' => 'باقة', 'plural' => 'الباقات', 'identity' => 'الهوية', 'visibility' => 'الظهور', 'pricing' => 'التسعير',
        'cycles' => 'خصومات مدة الاشتراك', 'cycles_help' => 'اتركها فارغة لاستخدام الافتراضي (0% / 5% / 10% / 20%).', 'subscribers' => 'المشتركون',
    ],
    'domain' => [
        'plural' => 'النطاقات', 'primary' => 'أساسي', 'verified' => 'موثّق', 'pending' => 'قيد التحقق', 'last_check' => 'آخر فحص', 'add' => 'ربط نطاق',
        'added' => 'تمت إضافة النطاق — شارك سجلات DNS مع المالك.', 'dns' => 'سجلات DNS', 'verify_now' => 'افحص الآن',
        'verified_ok' => 'تم توثيق النطاق وتأمينه', 'not_yet' => 'لم يتم التوثيق بعد', 'make_primary' => 'اجعله أساسياً',
        'dns_help' => 'أضف هذه السجلات لدى مسجل النطاق. نتحقق تلقائياً كل 10 دقائق ونصدر شهادة SSL فور ظهورها.',
    ],
    'impersonation' => ['label' => 'جلسة دعم', 'plural' => 'جلسات الدعم', 'not_used' => 'لم يُستخدم الرابط'],
    'lead' => ['label' => 'عميل محتمل', 'plural' => 'العملاء المحتملون', 'stages' => ['new' => 'جديد', 'contacted' => 'تم التواصل', 'demo' => 'عرض محجوز', 'won' => 'تم الاشتراك', 'lost' => 'خسارة']],
    'activity' => ['label' => 'سجل تدقيق', 'plural' => 'سجل التدقيق', 'event' => 'الحدث', 'by' => 'بواسطة', 'system' => 'النظام', 'subject' => 'العنصر', 'details' => 'التفاصيل'],
];

<?php

return [
    'kpi' => [
        'bookings' => 'حجوزات اليوم', 'bookings_hint' => 'المؤكدة والحضور المباشر · اتجاه 14 يوماً',
        'utilization' => 'نسبة الإشغال', 'utilization_hint' => 'الوقت المحجوز مقابل وقت الفريق المجدول',
        'revenue' => 'إيرادات اليوم', 'revenue_vs_target' => ':pct% من هدف :target', 'no_target' => 'لا يوجد هدف محدد',
        'staff_on_shift' => 'الفريق في المناوبة', 'staff_hint' => 'يعملن الآن',
        'low_stock' => 'مخزون منخفض', 'low_stock_hint' => 'منتجات عند الحد الأدنى أو أقل', 'all_good' => 'كل الرفوف ممتلئة',
        'pending_orders' => 'طلبات مفتوحة', 'pending_orders_hint' => 'بانتظار التجهيز أو الاستلام',
        'unread_messages' => 'رسائل غير مقروءة', 'unread_hint' => 'صندوق واتساب',
    ],

    'pulse' => [
        'title' => 'الفروع اليوم', 'subtitle' => 'نبض مباشر لكل فرع', 'empty' => 'لا توجد فروع بعد.',
        'staff' => '{0} لا أحد في المناوبة|{1} :count في المناوبة|[2,*] :count في المناوبة', 'of' => 'من', 'auto_target' => 'هدف تلقائي: متوسط يومي لآخر 28 يوماً',
    ],
];

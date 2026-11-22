<?php

return [
    'kpi' => [
        'bookings' => 'Bookings today', 'bookings_hint' => 'Confirmed & walk-ins · 14-day trend',
        'utilization' => 'Utilization', 'utilization_hint' => 'Booked vs scheduled staff time',
        'revenue' => 'Revenue today', 'revenue_vs_target' => ':pct% of :target target', 'no_target' => 'No target set',
        'staff_on_shift' => 'Staff on shift', 'staff_hint' => 'Working right now',
        'low_stock' => 'Low stock', 'low_stock_hint' => 'Items at or below threshold', 'all_good' => 'All shelves stocked',
        'pending_orders' => 'Open orders', 'pending_orders_hint' => 'Waiting to be prepared or collected',
        'unread_messages' => 'Unread messages', 'unread_hint' => 'WhatsApp inbox',
    ],

    'pulse' => [
        'title' => 'Branches today', 'subtitle' => 'Live pulse per branch', 'empty' => 'No branches yet.',
        'staff' => '{0} Nobody on shift|{1} :count on shift|[2,*] :count on shift', 'of' => 'of', 'auto_target' => 'Auto target: 28-day daily average',
    ],
];

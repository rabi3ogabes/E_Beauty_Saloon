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

    'reports' => [
        'from' => 'From', 'until' => 'Until', 'branch' => 'Branch', 'no_data' => 'No data for this period yet.',
        'export' => 'Export', 'export_excel' => 'Excel (.xlsx)', 'export_pdf' => 'PDF',
        'presets' => ['last_7' => '7 days', 'last_30' => '30 days', 'this_month' => 'This month', 'last_month' => 'Last month', 'last_90' => '90 days'],
        'vs_previous' => ':pct% vs previous period', 'no_previous' => 'No previous period to compare',
        'tickets_count' => '{0} No tickets|{1} :count ticket|[2,*] :count tickets',
        'cols' => [
            'branch' => 'Branch', 'pos' => 'In salon', 'shop' => 'Online shop', 'total' => 'Total', 'tickets' => 'Tickets', 'average_ticket' => 'Average ticket',
            'staff' => 'Staff', 'services' => 'Services', 'products' => 'Products', 'items' => 'Items', 'service' => 'Service', 'category' => 'Category',
            'count' => 'Count', 'product' => 'Product', 'units' => 'Units', 'date' => 'Date', 'metric' => 'Metric', 'value' => 'Value', 'day' => 'Day',
            'cohort' => 'First visit', 'clients' => 'Clients', 'campaign' => 'Campaign', 'sent' => 'Sent', 'booked' => 'Booked', 'revenue' => 'Revenue',
            'cost' => 'Cost', 'roi' => 'ROI', 'retail_attach' => 'Retail share', 'visits' => 'Visits', 'utilization' => 'Utilization',
            'rebook_rate' => 'Rebook rate', 'no_shows' => 'No-shows', 'rating' => 'Rating', 'starts_at' => 'Time', 'overlap' => 'Overlap (min)',
            'booking_a' => 'Booking', 'booking_b' => 'Conflicts with', 'phone' => 'Phone', 'cycle' => 'Usual cycle', 'days_since' => 'Days since visit',
            'overdue' => 'Overdue', 'variant' => 'Item', 'on_hand' => 'On hand', 'daily_usage' => 'Daily use', 'days_cover' => 'Days left', 'suggested' => 'Order qty',
        ],
        'revenue' => [
            'nav' => 'Revenue', 'title' => 'Revenue report', 'total' => 'Net revenue', 'ltv' => 'Client lifetime value',
            'ltv_hint' => ':visits visits on average · :clients clients', 'per_visit' => 'Value per visit', 'per_visit_hint' => 'Lifetime value ÷ visits',
            'by_branch' => 'By branch', 'by_staff' => 'By team member', 'by_service' => 'By service', 'by_product' => 'By product', 'daily' => 'Daily revenue',
            'definition' => 'Net of refunds and excluding tips. In-salon sales by completion time, shop orders by order time.',
            'commissions_link' => 'Commission payouts',
        ],
        'performance' => [
            'nav' => 'Performance', 'title' => 'Performance & retention', 'summary' => 'Summary', 'utilization' => 'Utilization',
            'utilization_hint' => ':booked h booked of :available h scheduled', 'no_show_rate' => 'No-show rate',
            'no_shows' => '{0} No no-shows|{1} :count no-show|[2,*] :count no-shows', 'rebook_rate' => 'Rebook rate', 'rebook_hint' => 'Booked again within :days days',
            'new_clients' => 'New clients', 'bookings' => 'Bookings', 'bookings_count' => '{0} No bookings|{1} :count booking|[2,*] :count bookings',
            'heatmap' => 'Utilization heatmap', 'heatmap_hint' => 'Booked share of scheduled staff time by weekday and hour', 'closed' => 'Closed',
            'cohorts' => 'Retention cohorts (share returning in later months)',
        ],
        'campaigns' => [
            'nav' => 'Campaign ROI', 'title' => 'Campaign ROI', 'subtitle' => 'Attributed booking revenue vs message cost',
            'revenue' => 'Attributed revenue', 'cost' => 'Message cost', 'roi' => 'Return', 'booked' => 'Bookings won',
        ],
        'scorecards' => [
            'nav' => 'Staff scorecards', 'title' => 'Staff scorecards', 'subtitle' => 'Revenue, utilization, rebooking and client love per team member',
            'team' => 'Team members', 'top' => 'Top performer', 'avg_util' => 'Average utilization', 'avg_rebook' => 'Average rebook rate',
        ],
    ],

    'morning' => [
        'subject' => 'Good morning — :branch, :date',
        'greeting' => 'Good morning ☀️ :branch · :date',
        'yesterday' => 'Yesterday: :revenue revenue (target :target), :visits visits, :no_shows no-shows, :utilization% utilization.',
        'today' => 'Today: :bookings bookings, first at :first, :utilization% of the team booked so far.',
        'low_stock' => 'Low stock: :count item(s) need reordering.',
    ],

    'settings' => [
        'nav' => 'Insights & alerts', 'title' => 'Insights & alerts', 'subtitle' => 'Morning report, targets, owner alerts and client sharing', 'saved' => 'Settings saved',
        'morning' => 'Morning report', 'morning_help' => 'A short summary for each branch every morning, at the branch’s local time.',
        'morning_enabled' => 'Send the morning report', 'by_email' => 'By email', 'by_whatsapp' => 'By WhatsApp', 'morning_time' => 'Send at (branch time)',
        'targets' => 'Daily revenue targets', 'targets_help' => 'Leave empty to use the 28-day daily average.', 'target_default' => 'Default daily target', 'target_branch' => 'Target',
        'alerts' => 'Owner alerts', 'alerts_help' => 'Delivered to your notification bell — and WhatsApp if enabled.', 'big_sale_threshold' => 'Big sale from',
        'alerts_whatsapp' => 'Also send alerts by WhatsApp', 'alerts_whatsapp_to' => 'WhatsApp number (defaults to your profile phone)',
        'clients' => 'Clients & consultations', 'clients_help' => 'Privacy between branches and consultation forms.',
        'client_sharing' => 'Share clients across branches', 'client_sharing_help' => 'When off, branch managers and front desk only see clients of their own branches.',
        'consultation_auto' => 'Send the consultation form after booking', 'consultation_auto_help' => 'Only when the client has no signed form that is still valid.',
        'consultation_valid' => 'A signed form stays valid for', 'months' => 'months',
        'ai_copy' => 'Let AI write insight cards', 'ai_copy_help' => 'Uses AI credits; only aggregated numbers are shared, never client details.',
    ],

    'alerts' => [
        'types' => ['big_sale' => 'Big sale', 'vip_checkin' => 'VIP check-in', 'bad_review' => 'Bad review', 'low_stock' => 'Low stock', 'failed_payment' => 'Failed payment'],
    ],
];

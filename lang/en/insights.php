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

    'hub' => [
        'nav' => 'Insights', 'title' => 'Insights', 'subtitle' => 'What your numbers are telling you this week',
        'refresh' => 'Refresh now', 'refreshed' => 'Insights refreshed',
        'tabs' => ['cards' => 'This week', 'churn' => 'Churn risk', 'forecast' => 'Demand forecast', 'reorder' => 'Reorder', 'pricing' => 'Pricing hints', 'ask' => 'Ask your data'],
        'empty_title' => 'No insights yet', 'empty_body' => 'Cards appear every night once there is enough booking and sales history.',
        'widget_title' => 'This week’s insights', 'see_all' => 'See all insights',
    ],

    'cards' => [
        'off_peak' => ['title' => ':day :from–:to is :empty% empty — launch a happy-hour offer?', 'body' => 'Over the last 4 weeks only :util% of team time was booked in this window. A 15% happy-hour offer to opted-in clients usually fills it.'],
        'reorder' => ['title' => ':count item(s) will run out within two weeks', 'body' => 'Based on the last 28 days of use. A purchase order of about :value covers the next month.'],
        'churn_risk' => ['title' => ':count regular clients are overdue for a visit', 'body' => 'They usually come back on a steady cycle but are now about :days days late. Together they are worth :value in lifetime value — a warm win-back message works best.'],
        'no_show' => ['title' => 'No-shows are at :rate%', 'body' => ':count missed appointments in the last 4 weeks. Asking for a deposit on long services usually halves no-shows.'],
        'peak_pricing' => ['title' => ':day :from–:to is :util% booked', 'body' => 'Your busiest window. Consider a :change% peak price for this slot — you approve, nothing changes automatically.'],
        'revenue_up' => ['title' => 'Revenue is up :pct% this week', 'body' => ':revenue in the last 7 days, led by :top. Keep the momentum with a rebooking reminder.'],
        'revenue_down' => ['title' => 'Revenue is down :pct% this week', 'body' => ':revenue in the last 7 days. :top is still your strongest service — a short offer can bring clients back.'],
        'demand' => ['title' => ':day looks busy at :branch', 'body' => 'About :forecast bookings expected (:booked already booked). Plan for at least :staff team member(s) on the floor.'],
        'actions' => ['campaign_draft' => 'Create campaign draft', 'purchase_order_draft' => 'Draft purchase order', 'pricing_review' => 'Approve hint', 'open_page' => 'Open'],
        'done' => ['campaign_draft' => 'Campaign draft created', 'purchase_order_draft' => 'Purchase order draft created', 'pricing_review' => 'Pricing hint approved — update your prices when ready'],
        'status' => ['new' => 'New', 'done' => 'Done', 'approved' => 'Approved', 'dismissed' => 'Dismissed'],
        'dismiss' => 'Dismiss', 'no_action' => 'This insight has no one-click action.', 'open_campaign' => 'Open campaign', 'open_po' => 'Open purchase order',
        'by_ai' => 'Written by AI from aggregated numbers', 'by_rules' => 'Written from templates', 'rules' => 'Rules',
    ],

    'drafts' => [
        'opted_in_segment' => 'Opted in on WhatsApp', 'win_back_segment' => 'Not visited in :days days', 'po_note' => 'Drafted from an insight suggestion',
        'happy_hour' => [
            'name' => 'Happy hour · :day :from–:to', 'subject' => 'Your :day treat at :salon',
            'body' => 'Hi {first_name}, enjoy :discount% off every :day between :from and :to at :salon. Book your moment: {link}',
        ],
        'win_back' => [
            'name' => 'We miss you · win-back', 'subject' => 'We miss you at :salon',
            'body' => 'Hi {first_name}, it has been a while! Your chair at :salon is waiting — book your next visit here: {link}',
        ],
    ],

    'churn' => [
        'title' => 'Clients at risk', 'subtitle' => 'Overdue compared with their own usual visit cycle', 'empty' => 'Nobody is overdue — lovely.',
        'draft' => 'Create win-back draft', 'message_col' => 'Suggested message', 'days' => '{1} :count day|[2,*] :count days',
        'message' => 'Hi :name, we miss you at :salon! Shall we save you a spot this week?',
    ],

    'forecast' => [
        'title' => 'Demand forecast', 'subtitle' => 'Expected bookings per day for the next two weeks',
        'booked' => ':count booked', 'staff' => '{0} no extra staff|{1} :count on the floor|[2,*] :count on the floor',
        'explain' => 'Last four :weekday: :samples → average :avg × trend :trend',
        'method' => 'Average of the same weekday over the last 4 weeks × the 28-day trend (clipped 0.8–1.25). Staff = expected booked hours ÷ 8.',
    ],

    'reorder' => [
        'draft' => 'Draft purchase order', 'empty' => 'Stock levels look healthy.',
        'method' => 'Daily use from the last 28 days; suggested when fewer than 14 days remain, sized for 30 days plus the safety threshold.',
    ],

    'pricing' => [
        'title' => 'Smart pricing hints', 'subtitle' => 'Peak and off-peak suggestions from the last 4 weeks', 'empty' => 'No strong peaks or quiet windows yet.',
        'peak' => 'Peak', 'off_peak' => 'Off-peak', 'util' => ':pct% booked',
        'note' => 'Suggestions only — prices never change automatically. Apply approved hints in your catalog or as a promotion.',
    ],

    'ask' => [
        'title' => 'Ask your data', 'subtitle' => 'Answered from safe, read-only reports of your salon only',
        'placeholder' => 'e.g. How much did The Pearl make from color services in October?', 'button' => 'Ask',
        'examples' => ['Revenue last month', 'No-show rate this month', 'Top services in the last 30 days', 'Which branch made the most this month?'],
        'empty' => 'Type a question first.', 'not_understood' => 'I can answer questions about revenue, bookings, average ticket, no-shows, rebooking, utilization, new clients and top services or staff.',
        'numbers_used' => 'Numbers used', 'query_used' => 'Query used (:source)',
        'rejected' => [
            'shape' => 'That question could not be turned into a safe report query.', 'metric' => '":metric" is not one of the reports I can run.',
            'filter' => '":filter" is not an allowed filter for this report.', 'value' => 'I could not find ":value" in your salon.',
            'period' => 'That period is not supported.', 'permission' => 'You need revenue access for this report.',
        ],
        'metrics' => ['revenue' => 'Revenue', 'bookings' => 'Bookings', 'average_ticket' => 'Average ticket', 'no_show_rate' => 'No-show rate', 'rebook_rate' => 'Rebook rate', 'utilization' => 'Utilization', 'new_clients' => 'New clients'],
        'channels' => ['pos' => 'in salon', 'shop' => 'online shop'],
        'scope' => ['branch' => 'at :name', 'service_category' => 'from :name', 'service' => 'from :name', 'staff' => 'by :name', 'product' => 'from :name', 'channel' => '(:name)'],
        'answers' => [
            'revenue' => 'Revenue:scope was :value (:period).', 'bookings' => 'There were :value bookings:scope (:period).',
            'average_ticket' => 'The average ticket:scope was :value (:period).', 'no_show_rate' => 'The no-show rate:scope was :value (:period).',
            'rebook_rate' => 'The rebook rate:scope was :value (:period).', 'utilization' => 'Utilization:scope was :value (:period).',
            'new_clients' => 'You welcomed :value new clients (:period).', 'top_services' => 'Your top service:scope was :top with :value (:period).',
            'top_staff' => 'Your top team member:scope was :top with :value (:period).', 'revenue_by_branch' => ':top led with :value (:period).',
        ],
    ],

    'assistant' => [
        'inbox' => 'WhatsApp inbox', 'ai_on' => 'The AI receptionist is answering inside its hours', 'ai_off' => 'The AI receptionist is off — every message waits for your team',
        'filters' => ['all' => 'All', 'unread' => 'Unread', 'handoff' => 'Needs a human'],
        'empty' => 'No WhatsApp conversations yet.', 'pick' => 'Choose a conversation to read and reply.', 'human' => 'Human',
        'last_booking' => 'last booking :ref', 'resume' => 'Hand back to AI', 'take_over' => 'Take over', 'resumed' => 'The AI receptionist is answering again',
        'reply_placeholder' => 'Write a reply…', 'send' => 'Send', 'reply_hint' => 'Replying pauses the AI for this conversation until you hand it back.',
        'staff' => 'Team', 'authors' => ['client' => 'Client', 'bot' => 'AI receptionist', 'system' => 'System'],
        'handoff_reasons' => ['requested' => 'Asked for a person', 'low_confidence' => 'AI was unsure', 'ai_unavailable' => 'AI unavailable', 'tool' => 'AI handed over', 'staff' => 'Team took over'],
        'handoff_reply' => 'Of course — a member of the :salon team will reply to you here shortly.',
        'booked_note' => 'Booked by the WhatsApp AI receptionist', 'non_text' => 'media message',
        'empty_reply' => 'Write a message first.', 'send_failed' => 'The message could not be sent. Check your WhatsApp channel.',
        'settings' => [
            'nav' => 'AI receptionist', 'title' => 'WhatsApp AI receptionist', 'subtitle' => 'Answers FAQs, prices and hours, checks availability and books — or hands over to your team',
            'duty' => 'On duty', 'duty_help' => 'Switch it on and choose when it answers (salon time). Outside these hours messages wait in the inbox.',
            'enabled' => 'AI receptionist is on', 'enabled_help' => 'Uses AI credits per reply step.', 'hours_from' => 'Answers from', 'hours_to' => 'Until', 'hours_help' => 'Use 24:00 for midnight.',
            'knowledge' => 'What it knows', 'knowledge_help' => 'Services, prices, hours and availability come from your workspace. Add anything else here.',
            'tone' => 'Tone of voice', 'faq' => 'FAQ & policies', 'faq_placeholder' => "Parking: free valet at the entrance\nPayment: card, cash, Apple Pay\nKids: welcome from age 6",
            'handoff' => 'Human handoff', 'handoff_help' => 'The AI hands over when the client asks for a person, when it is unsure, or for complaints and medical questions.',
            'keywords' => 'Handoff words', 'min_confidence' => 'Minimum confidence to reply (0–1)',
        ],
    ],

    'content' => [
        'nav' => 'Content assistant', 'title' => 'Content assistant', 'subtitle' => 'Descriptions, offers and captions in English and Arabic · :credits credit(s) per text',
        'kind' => 'What to write', 'kinds' => ['service_description' => 'Service description', 'offer' => 'Offer message', 'caption' => 'Social caption'],
        'tone' => 'Tone', 'tones' => ['luxury' => 'Quiet luxury', 'warm' => 'Warm & friendly', 'playful' => 'Playful', 'minimal' => 'Minimal'],
        'service' => 'Service (optional)', 'brief' => 'Brief', 'brief_placeholder' => 'e.g. Eid glow facial with gold mask, 20% off this week',
        'generate' => 'Write it', 'apply' => 'Use as service description', 'applied' => 'Service description updated',
        'used' => '{1} :count AI credit used|[2,*] :count AI credits used', 'wallet' => 'AI credits',
        'unavailable' => 'AI is not available right now — check your AI credits.', 'invalid_kind' => 'Choose what to write.',
    ],
];

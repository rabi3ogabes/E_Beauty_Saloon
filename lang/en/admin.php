<?php

return [
    'all' => 'All', 'days' => 'days', 'never' => 'Never', 'last_login' => 'Last login',
    'cycle' => [1 => 'Monthly', 3 => '3 months', 6 => '6 months', 12 => 'Yearly'],
    'dashboard' => [
        'greeting' => 'Good to see you, :name',
        'arr' => 'ARR :amount', 'active_salons' => 'Active salons', 'trials' => ':count on trial', 'churn' => 'Churn',
        'last_30' => 'Last 30 days', 'renewals' => 'Renewals in the next 7 days', 'overdue' => ':count overdue invoices',
        'revenue_by_module' => 'Monthly revenue by module', 'collected' => 'Collected revenue — last 12 months',
        'plan' => 'Base plans', 'branches' => 'Extra branches', 'no_renewals' => 'No renewals due soon',
    ],
    'tenant' => [
        'label' => 'Salon', 'plural' => 'Salons', 'new' => 'New salon', 'trials_badge' => 'Salons on trial',
        'business' => 'Business details', 'legal_name' => 'Legal name', 'tax_number' => 'Tax number', 'overview' => 'Overview',
        'subscription' => 'Subscription', 'no_plan' => 'No active plan', 'subscriptions' => 'Subscriptions', 'team' => 'Team logins',
        'empty' => 'No salons yet', 'empty_body' => 'Create your first salon account to get started.',
        'step_salon' => 'Salon & owner', 'step_plan' => 'Plan & modules', 'extra_modules' => 'Add-on modules',
        'included' => 'Included in this plan: :modules', 'quote' => 'Price breakdown', 'quote_hint' => 'Choose a plan and billing cycle to see the price.',
        'created' => ':salon is ready.',
        'impersonate_heading' => 'Open :salon as its owner',
        'impersonate_body' => 'A support session opens in a new tab with a gold banner. It is logged and visible to the owner in their security log.',
        'impersonate_placeholder' => 'e.g. Owner asked for help configuring SADAD',
        'extend_trial' => 'Extend trial', 'trial_extended' => 'Trial extended to :date',
        'suspend' => 'Suspend', 'suspend_body' => 'The dashboard is locked and the website shows "temporarily unavailable".', 'suspended' => 'Salon suspended',
        'reactivate' => 'Reactivate', 'reactivated' => 'Salon reactivated',
        'change_plan' => 'Change plan', 'prorated_charge' => 'A prorated invoice of :amount will be issued for the rest of this cycle.',
        'prorated_credit' => 'A credit of :amount will be applied to the next renewal.',
        'proration_invoiced' => 'Prorated invoice :number issued — the change applies once paid.', 'plan_changed' => 'Plan updated',
    ],
    'invoice' => [
        'label' => 'Invoice', 'plural' => 'Invoices', 'mark_paid' => 'Record payment', 'paid_ok' => 'Payment recorded for :number',
        'paid' => 'Paid', 'items' => 'Line items', 'tax_invoice' => 'Tax invoice', 'billed_to' => 'Billed to', 'qty' => 'Qty', 'unit' => 'Unit',
        'footer' => 'Thank you for growing with :platform.',
        'types' => ['subscription' => 'Subscription', 'renewal' => 'Renewal', 'proration' => 'Proration', 'credits' => 'Credits', 'addon' => 'Add-on'],
    ],
    'payment' => [
        'label' => 'Payment', 'plural' => 'Payments', 'proof' => 'Proof', 'approve' => 'Approve', 'reject' => 'Reject', 'approved' => 'Transfer approved — invoice paid',
        'status' => ['pending' => 'Pending', 'awaiting_approval' => 'Awaiting approval', 'succeeded' => 'Succeeded', 'failed' => 'Failed', 'superseded' => 'Applied', 'refunded' => 'Refunded'],
    ],
    'coupon' => [
        'label' => 'Coupon', 'plural' => 'Coupons', 'percent' => 'Percent', 'fixed' => 'Fixed amount', 'first_cycle' => 'First cycle', 'forever' => 'Every cycle',
        'value_help' => 'Percent (e.g. 20) or amount (e.g. 50.00).',
    ],
    'module' => ['label' => 'Module', 'plural' => 'Modules', 'per_account' => 'Per account', 'per_branch' => 'Per branch', 'prices' => 'Prices', 'any_plan' => 'Any plan'],
    'plan' => [
        'label' => 'Plan', 'plural' => 'Plans', 'identity' => 'Identity', 'visibility' => 'Visibility', 'pricing' => 'Pricing',
        'cycles' => 'Billing cycle discounts', 'cycles_help' => 'Leave empty to use platform defaults (0% / 5% / 10% / 20%).', 'subscribers' => 'Subscribers',
    ],
    'domain' => [
        'plural' => 'Domains', 'primary' => 'Primary', 'verified' => 'Verified', 'pending' => 'Pending', 'last_check' => 'Last check', 'add' => 'Connect domain',
        'added' => 'Domain added — share the DNS records with the owner.', 'dns' => 'DNS records', 'verify_now' => 'Check now',
        'verified_ok' => 'Domain verified and secured', 'not_yet' => 'Not verified yet', 'make_primary' => 'Make primary',
        'dns_help' => 'Add these records at the domain registrar. We check automatically every 10 minutes and issue SSL as soon as they resolve.',
    ],
    'impersonation' => ['label' => 'Support session', 'plural' => 'Support sessions', 'not_used' => 'Link not used'],
    'lead' => ['label' => 'Lead', 'plural' => 'Leads', 'stages' => ['new' => 'New', 'contacted' => 'Contacted', 'demo' => 'Demo booked', 'won' => 'Won', 'lost' => 'Lost']],
    'activity' => ['label' => 'Audit entry', 'plural' => 'Audit log', 'event' => 'Event', 'by' => 'By', 'system' => 'System', 'subject' => 'Subject', 'details' => 'Details'],
];

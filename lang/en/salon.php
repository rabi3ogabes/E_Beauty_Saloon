<?php

return [
    'tenant_status' => ['trial' => 'Trial', 'active' => 'Active', 'grace' => 'Grace period', 'suspended' => 'Suspended', 'cancelled' => 'Cancelled'],
    'subscription_status' => ['pending' => 'Awaiting payment', 'trialing' => 'Trialing', 'active' => 'Active', 'past_due' => 'Past due', 'grace' => 'Grace', 'suspended' => 'Suspended', 'cancelled' => 'Cancelled', 'expired' => 'Expired'],
    'invoice_status' => ['draft' => 'Draft', 'open' => 'Open', 'paid' => 'Paid', 'void' => 'Void', 'refunded' => 'Refunded'],

    'nav' => [
        'salons' => 'Salons', 'billing' => 'Billing', 'growth' => 'Growth', 'ai' => 'AI', 'website' => 'Website', 'platform' => 'Platform',
        'today' => 'Today', 'bookings' => 'Bookings', 'sales' => 'Sales', 'clients' => 'Clients', 'catalog' => 'Catalog', 'inventory' => 'Inventory',
        'shop' => 'Shop', 'marketing' => 'Marketing', 'ai_studio' => 'AI Studio', 'team' => 'Team', 'reports' => 'Reports', 'settings' => 'Settings',
    ],

    'ui' => [
        'language' => 'Language', 'branch' => 'Branch', 'all_branches' => 'All branches', 'save' => 'Save', 'cancel' => 'Cancel', 'continue' => 'Continue',
        'back' => 'Back', 'search' => 'Search', 'english' => 'English', 'arabic' => 'Arabic', 'name' => 'Name', 'status' => 'Status', 'actions' => 'Actions',
        'created' => 'Created', 'copy' => 'Copy', 'copied' => 'Copied', 'view' => 'View', 'edit' => 'Edit', 'delete' => 'Delete', 'none' => 'None',
        'yes' => 'Yes', 'no' => 'No', 'optional' => 'Optional', 'minutes' => 'min', 'per_month' => '/ month', 'learn_more' => 'Learn more',
    ],

    'modules' => [
        'booking' => 'Online booking', 'pos' => 'Point of sale', 'shop' => 'Online shop', 'marketing' => 'Marketing & CRM',
        'ai_studio' => 'AI Beauty Studio', 'website' => 'Luxury website', 'loyalty' => 'Loyalty & referrals',
    ],

    'billing' => [
        'line_plan' => ':plan plan × :months mo',
        'line_extra_branches' => ':count extra branch(es) × :months mo',
        'line_module_branch' => ':module · :branches branch(es) × :months mo',
        'line_module' => ':module × :months mo',
        'line_cycle_discount' => 'Billing cycle discount (:percent%)',
        'line_coupon' => 'Coupon :code',
        'line_proration' => 'Prorated change for the remaining :days days',
        'line_credit' => 'Credit from previous plan change',
        'reminder_subject' => 'Your subscription renews in :days day(s)',
        'hello' => 'Hello :name,',
        'reminder_line' => 'Your salon subscription renews in :days day(s), on :date.',
        'amount_due' => 'Amount due: :amount (invoice :number).',
        'pay_now' => 'Pay now',
        'suspended_subject' => 'Your salon dashboard is paused',
        'suspended_line' => 'We could not renew your subscription. Your website shows a polite "temporarily unavailable" page until payment is received.',
        'reactivate' => 'Reactivate',
        'grace_banner' => 'Your subscription has ended. The dashboard is read-only until :date.',
        'subtotal' => 'Subtotal', 'discount' => 'Discount', 'tax' => 'Tax', 'total' => 'Total', 'per_month_equiv' => '≈ :amount / month',
        'cycle' => '{1} Monthly|[2,*] Every :count months',
    ],

    'payments' => [
        'missing_credentials' => 'Please fill in all credentials first.',
        'test_ok' => 'Connection successful.',
        'test_unreachable' => 'Provider is unreachable right now.',
        'test_failed' => 'The provider rejected these credentials.',
        'methods' => ['cash' => 'Cash', 'card_terminal' => 'Card terminal', 'online' => 'Online', 'wallet' => 'Wallet', 'gift_card' => 'Gift card', 'points' => 'Loyalty points', 'bank_transfer' => 'Bank transfer', 'cod' => 'Cash on delivery'],
        'drivers' => ['sadad' => 'SADAD', 'skipcash' => 'SkipCash', 'myfatoorah' => 'MyFatoorah', 'tap' => 'Tap', 'stripe' => 'Stripe', 'cash' => 'Cash', 'card_terminal' => 'Card terminal', 'bank_transfer' => 'Bank transfer', 'cod' => 'Cash on delivery'],
        'success_title' => 'Payment received', 'success_body' => 'Thank you — your payment is confirmed.',
        'pending_title' => 'Confirming your payment', 'pending_body' => 'This usually takes a few seconds. You can safely close this page.',
        'failed_title' => 'Payment not completed', 'failed_body' => 'No money was taken. Please try again or choose another method.',
    ],

    'impersonation' => [
        'expired' => 'This sign-in link has expired. Please start again from the admin panel.',
        'banner' => 'You are viewing as :owner (support session by :admin)',
        'exit' => 'Exit',
        'reason' => 'Reason for access',
        'login_as_owner' => 'Login as owner',
    ],

    'errors' => [
        'insufficient_stock' => 'Not enough stock for :item (available: :available).',
        'same_branch_transfer' => 'Choose two different branches for a transfer.',
        'insufficient_wallet' => 'Wallet balance is not enough (balance: :balance).',
        'gift_card_invalid' => 'This gift card is not valid.',
        'gift_card_balance' => 'Gift card balance is not enough (balance: :balance).',
        'insufficient_points' => 'Not enough loyalty points (balance: :points).',
        'invalid_cycle' => 'Please choose a billing cycle of 1, 3, 6 or 12 months.',
        'unknown_module' => 'Unknown module: :module.',
        'coupon_invalid' => 'This coupon is not valid or has expired.',
        'slug_reserved' => 'This address is reserved. Please choose another.',
        'email_taken' => 'An account with this email already exists.',
        'domain_invalid' => 'Please enter a valid domain, e.g. book.mysalon.com.',
        'domain_reserved' => 'This domain cannot be used.',
        'domain_taken' => 'This domain is already connected to another salon.',
        'no_owner' => 'This salon has no owner account.',
        'read_only' => 'Your subscription has ended — the dashboard is read-only until renewal.',
        'module_disabled' => 'This feature is not part of your current plan.',
        'refund_amount' => 'Refund amount is not valid.',
        'refund_needs_client' => 'Store credit requires a client on the sale.',
        'gateway_refund_failed' => 'The payment provider could not process the refund.',
        'branch_limit' => 'Your plan includes :limit branch(es). Upgrade to add more.',
    ],

    'unavailable' => [
        'title' => 'We\'ll be right back',
        'body' => ':salon is temporarily unavailable online. Please contact us directly to book.',
    ],
];

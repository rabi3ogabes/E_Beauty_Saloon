<?php

return [
    'errors' => [
        'unauthenticated' => 'Please sign in again.',
        'wrong_app' => 'This sign-in is not valid for this app.',
        'forbidden' => 'You do not have access to this.',
        'not_found' => 'Not found.',
        'method_not_allowed' => 'This action is not supported.',
        'conflict' => 'This request conflicts with the current state. Please refresh.',
        'read_only' => 'The salon account is read-only at the moment.',
        'too_many_requests' => 'Too many attempts. Please wait a moment.',
        'server_error' => 'Something went wrong on our side. Please try again.',
        'validation_failed' => 'Please check the highlighted fields.',
        'domain_error' => 'This request could not be completed.',
        'module_disabled' => 'This feature is not available for this salon.',
        'error' => 'Something went wrong.',
        'idempotency_key_invalid' => 'The Idempotency-Key header is too long (max 100 characters).',
        'idempotency_in_progress' => 'The same request is still being processed. Please retry in a moment.',
        'idempotency_mismatch' => 'This Idempotency-Key was already used for a different request.',
    ],

    'booking' => [
        'cannot_cancel' => 'This booking can no longer be cancelled.',
    ],

    'auth' => [
        'invalid_credentials' => 'These credentials do not match our records.',
        'not_allowed' => 'Your account cannot use this app. Ask the salon owner for access.',
        'mfa_required' => 'Enter the 6-digit code from your authenticator app.',
        'mfa_invalid' => 'The authenticator code is not valid.',
        'inactive' => 'Your account is disabled.',
    ],

    'studio' => [
        'consent_required' => 'Please accept the AI photo consent first.',
        'style_category_mismatch' => 'This style does not belong to the selected category.',
        'look_not_saved' => 'Only finished looks can be saved.',
    ],

    'packages' => [
        'checkout_required' => 'Complete the payment to activate your package.',
    ],

    'devices' => [
        'registered' => 'Device registered for notifications.',
    ],

    'staff' => [
        'no_profile' => 'Your account is not linked to a stylist profile.',
        'not_your_booking' => 'You can only check in your own appointments.',
        'time_off_requested' => 'Time off requested. The salon will review it.',
        'time_off_overlaps' => 'You already have time off in this period.',
        'checked_in_from_app' => 'Checked in from the staff app by :name',
        'commission' => 'Commission',
    ],

    'owner' => [
        'campaign_queued' => 'Campaign is on its way to :count clients.',
        'quick_campaign' => 'Quick campaign :date',
        'alerts' => [
            'low_stock' => 'Low stock: :count products',
            'low_stock_body' => ':items',
            'time_off' => 'Time-off requests waiting: :count',
            'time_off_body' => ':names',
            'failed_payments' => 'Failed payments (24h): :count',
            'failed_payments_body' => 'Online payments that did not go through in the last 24 hours.',
            'ai_credits' => 'AI Studio credits are low (:balance left)',
            'ai_credits_body' => 'Top up so clients can keep trying looks.',
            'message_credits' => 'Message credits are low (:balance left)',
            'message_credits_body' => 'Top up before sending the next campaign.',
            'bad_review' => ':rating★ review from :client',
            'bad_review_body' => ':comment',
        ],
    ],

    'notifications' => [
        'campaign' => 'Message from :salon',
    ],
];

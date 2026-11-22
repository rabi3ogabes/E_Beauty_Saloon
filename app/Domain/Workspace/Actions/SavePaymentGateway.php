<?php

namespace App\Domain\Workspace\Actions;

use App\Domain\Payments\Models\TenantPaymentGateway;
use App\Support\Exceptions\DomainException;

/**
 * Creates/updates a salon payment method. Credentials are encrypted by the model cast and are
 * never echoed back: blank credential inputs keep the stored secret, filled ones replace it.
 */
class SavePaymentGateway
{
    /** Credential fields per driver (offline drivers need none). */
    public const DRIVER_FIELDS = [
        'sadad' => ['merchant_id', 'secret_key', 'website'],
        'skipcash' => ['client_id', 'key_id', 'key_secret', 'webhook_key'],
        'myfatoorah' => ['api_key', 'webhook_secret'],
        'tap' => ['secret_key'],
        'stripe' => ['secret_key', 'webhook_secret'],
        'cash' => [], 'card_terminal' => [], 'bank_transfer' => [], 'cod' => [],
    ];

    /** Non-secret fields that may be shown back in clear text. */
    public const PUBLIC_FIELDS = ['merchant_id', 'website', 'client_id', 'key_id'];

    public const CONTEXTS = ['booking', 'shop', 'pos', 'deposits'];

    public function handle(?TenantPaymentGateway $gateway, array $data): TenantPaymentGateway
    {
        abort_if(tenant()?->isReadOnly(), 423, __('salon.errors.read_only'));

        $driver = $data['driver'] ?? $gateway?->driver;
        if (! array_key_exists((string) $driver, self::DRIVER_FIELDS)) {
            throw new DomainException(__('workspace.payments.unknown_driver'));
        }

        $existing = ($gateway && $gateway->driver === $driver) ? ($gateway->credentials ?? []) : [];
        $incoming = (array) ($data['credentials'] ?? []);
        $credentials = [];
        foreach (self::DRIVER_FIELDS[$driver] as $field) {
            $credentials[$field] = filled($incoming[$field] ?? null) ? trim((string) $incoming[$field]) : ($existing[$field] ?? null);
        }

        $attributes = [
            'driver' => $driver,
            'label' => $data['label'] ?? null,
            'mode' => in_array($data['mode'] ?? 'test', ['test', 'live'], true) ? ($data['mode'] ?? 'test') : 'test',
            'enabled_for' => array_values(array_intersect(self::CONTEXTS, (array) ($data['enabled_for'] ?? []))),
            'branch_ids' => array_values(array_map('intval', (array) ($data['branch_ids'] ?? []))) ?: null,
            'fee_percent' => (float) ($data['fee_percent'] ?? 0),
            'fee_fixed' => (int) ($data['fee_fixed'] ?? 0),
            'is_active' => (bool) ($data['is_active'] ?? false),
            'sort' => (int) ($data['sort'] ?? $gateway?->sort ?? 0),
            'credentials' => array_filter($credentials, fn ($v) => $v !== null) ?: null,
        ];

        if ($gateway) {
            $credentialsChanged = $attributes['credentials'] !== ($gateway->credentials ?: null) || $attributes['mode'] !== $gateway->mode;
            $gateway->update($attributes + ($credentialsChanged ? ['last_tested_at' => null, 'last_test_ok' => null] : []));
        } else {
            $gateway = TenantPaymentGateway::create($attributes);
        }

        activity()->performedOn($gateway)->causedBy(auth()->user())->withProperties(['driver' => $driver, 'mode' => $attributes['mode']])->log('payments.gateway_saved');

        return $gateway;
    }

    /** "••••1234" style mask for a stored secret. */
    public static function mask(?string $value): ?string
    {
        if (blank($value)) {
            return null;
        }

        return str_repeat('•', 8).mb_substr($value, -4);
    }
}

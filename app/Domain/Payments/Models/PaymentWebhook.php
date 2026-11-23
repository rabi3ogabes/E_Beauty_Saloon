<?php

namespace App\Domain\Payments\Models;

use Illuminate\Database\Eloquent\Model;

class PaymentWebhook extends Model
{
    protected $table = 'payment_webhooks';

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'payload' => 'array',
            'processed_at' => 'datetime',
        ];
    }
}

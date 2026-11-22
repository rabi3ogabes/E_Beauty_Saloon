<?php

namespace App\Domain\Marketing\Models;

use App\Domain\Tenancy\Models\Tenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Prepaid balance (minor units) used for platform-shared WhatsApp/SMS. Not tenant-scoped on purpose:
 * the super admin tops it up from the central panel. Always query through MessageCredits.
 */
class MessageCreditWallet extends Model
{
    protected $guarded = ['id'];

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }
}

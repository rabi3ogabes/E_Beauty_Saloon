<?php

namespace App\Domain\CRM\Models;

use App\Support\Tenancy\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ClientConsent extends Model
{
    use BelongsToTenant;

    protected $table = 'client_consents';

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'granted' => 'boolean',
        ];
    }

    public function client(): BelongsTo
    {
        return $this->belongsTo(Client::class);
    }
}

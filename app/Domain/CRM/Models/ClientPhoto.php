<?php

namespace App\Domain\CRM\Models;

use App\Support\Tenancy\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ClientPhoto extends Model
{
    use BelongsToTenant;

    protected $table = 'client_photos';

    protected $guarded = ['id'];

    public function client(): BelongsTo
    {
        return $this->belongsTo(Client::class);
    }
}

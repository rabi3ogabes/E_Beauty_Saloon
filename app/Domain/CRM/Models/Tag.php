<?php

namespace App\Domain\CRM\Models;

use App\Support\Tenancy\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

class Tag extends Model
{
    use BelongsToTenant;

    protected $table = 'tags';

    protected $guarded = ['id'];

    public function clients(): BelongsToMany
    {
        return $this->belongsToMany(Client::class);
    }
}

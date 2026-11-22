<?php

namespace App\Domain\Catalog\Models;

use App\Support\Tenancy\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Spatie\Translatable\HasTranslations;

class ServiceAddon extends Model
{
    use BelongsToTenant, HasTranslations;

    protected $table = 'service_addons';

    protected $guarded = ['id'];

    public array $translatable = ['name'];

    public function service(): BelongsTo
    {
        return $this->belongsTo(Service::class);
    }
}

<?php

namespace App\Domain\Shop\Models;

use App\Domain\Catalog\Models\Product;
use App\Domain\Team\Models\Staff;
use App\Support\Tenancy\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** "Recommended by your stylist" — a product a staff member recommends. */
class StaffPick extends Model
{
    use BelongsToTenant;

    protected $table = 'shop_staff_picks';

    protected $guarded = ['id'];

    public function staff(): BelongsTo
    {
        return $this->belongsTo(Staff::class)->withoutGlobalScopes();
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }
}

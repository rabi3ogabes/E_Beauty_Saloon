<?php

namespace App\Domain\Marketing\Models;

use App\Support\Tenancy\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;

class WhatsappTemplate extends Model
{
    use BelongsToTenant;

    public const CATEGORIES = ['MARKETING', 'UTILITY', 'AUTHENTICATION'];

    public const STATUSES = ['draft', 'pending', 'approved', 'rejected', 'paused'];

    protected $guarded = ['id'];

    protected $attributes = ['status' => 'draft', 'language' => 'en', 'category' => 'MARKETING', 'header_type' => 'none'];

    protected function casts(): array
    {
        return ['buttons' => 'array', 'synced_at' => 'datetime'];
    }

    public function isApproved(): bool
    {
        return $this->status === 'approved';
    }

    /** Number of {{n}} body placeholders. */
    public function placeholderCount(): int
    {
        preg_match_all('/\{\{(\d+)\}\}/', (string) $this->body, $m);

        return count(array_unique($m[1]));
    }
}

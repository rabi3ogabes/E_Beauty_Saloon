<?php

namespace App\Domain\Tenancy\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Domain extends Model
{
    protected $table = 'domains';

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'is_primary' => 'boolean',
            'verified_at' => 'datetime',
            'last_checked_at' => 'datetime',
        ];
    }

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }

    public function isVerified(): bool
    {
        return $this->verified_at !== null;
    }

    public function url(string $path = ''): string
    {
        $scheme = app()->isProduction() ? 'https' : request()->getScheme();
        $port = app()->isProduction() ? '' : (in_array(request()->getPort(), [80, 443, null]) ? '' : ':'.request()->getPort());

        return $scheme.'://'.$this->domain.$port.'/'.ltrim($path, '/');
    }
}

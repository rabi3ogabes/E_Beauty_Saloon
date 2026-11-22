<?php

namespace App\Domain\Marketing\Models;

use App\Support\Tenancy\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Storage;

class Banner extends Model
{
    use BelongsToTenant;

    /** format => [width, height] export size in px. */
    public const FORMATS = [
        'story' => [1080, 1920],    // 9:16
        'post' => [1080, 1080],     // 1:1
        'whatsapp' => [1200, 628],  // 1.91:1 (WhatsApp / link header)
    ];

    protected $guarded = ['id'];

    protected $attributes = ['format' => 'post'];

    protected function casts(): array
    {
        return ['design' => 'array'];
    }

    public function imageUrl(): ?string
    {
        return $this->exported_path ? Storage::disk('public')->url($this->exported_path) : null;
    }

    public function size(): array
    {
        return self::FORMATS[$this->format] ?? self::FORMATS['post'];
    }
}

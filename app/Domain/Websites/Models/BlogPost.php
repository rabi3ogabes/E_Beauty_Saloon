<?php

namespace App\Domain\Websites\Models;

use Illuminate\Database\Eloquent\Model;
use Spatie\Translatable\HasTranslations;

class BlogPost extends Model
{
    use HasTranslations;

    protected $guarded = ['id'];

    public array $translatable = ['title', 'excerpt', 'body', 'seo_title', 'seo_description'];

    protected function casts(): array
    {
        return ['tags' => 'array', 'published_at' => 'datetime'];
    }

    public function getRouteKeyName(): string
    {
        return 'slug';
    }

    public function scopePublished($q)
    {
        return $q->whereNotNull('published_at')->where('published_at', '<=', now())->latest('published_at');
    }

    public function readingMinutes(): int
    {
        return max(1, (int) ceil(str_word_count(strip_tags((string) $this->body)) / 220));
    }
}

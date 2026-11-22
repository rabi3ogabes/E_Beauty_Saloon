<?php

namespace App\Domain\Insights\Models;

use App\Domain\Branches\Models\Branch;
use App\Support\Tenancy\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A weekly insight: aggregated metrics + bilingual copy + an optional one-click action.
 * action: {"type": "campaign_draft"|"purchase_order_draft"|"pricing_review"|"open_page", "params": {...}}
 */
class InsightCard extends Model
{
    use BelongsToTenant;

    public const STATUSES = ['new', 'done', 'approved', 'dismissed'];

    protected $guarded = ['id'];

    protected $attributes = ['status' => 'new', 'source' => 'template', 'priority' => 50];

    protected function casts(): array
    {
        return ['title' => 'array', 'body' => 'array', 'metrics' => 'array', 'action' => 'array', 'result' => 'array', 'acted_at' => 'datetime'];
    }

    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }

    public function localized(string $field, ?string $locale = null): string
    {
        $value = (array) $this->{$field};
        $locale ??= app()->getLocale();

        return (string) ($value[$locale] ?? $value['en'] ?? reset($value) ?: '');
    }

    public function actionType(): ?string
    {
        return $this->action['type'] ?? null;
    }

    public function isOpen(): bool
    {
        return $this->status === 'new';
    }

    public function scopeOpen($q)
    {
        return $q->where('status', 'new');
    }
}

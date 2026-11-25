<?php

namespace App\Domain\AIStudio\Models;

use Illuminate\Database\Eloquent\Model;
use Spatie\Translatable\HasTranslations;

class AITaskPrice extends Model
{
    use HasTranslations;

    protected $table = 'ai_task_prices';

    protected $guarded = ['id'];

    public array $translatable = ['name'];

    /** Built-in tasks with default credit costs (the super admin can change them). */
    public const DEFAULTS = [
        'hair_tryon' => ['Hair try-on', 'تجربة الشعر', 'image', 4, ['gemini', 'openrouter', 'openai', 'higgsfield']],
        'eyes_tryon' => ['Eyes & brows try-on', 'تجربة العيون والحواجب', 'image', 3, ['gemini', 'openrouter', 'openai']],
        'lips_tryon' => ['Lips try-on', 'تجربة الشفاه', 'image', 2, ['gemini', 'openrouter', 'openai']],
        'nails_tryon' => ['Nails try-on', 'تجربة الأظافر', 'image', 2, ['gemini', 'openrouter', 'openai']],
        'pedicure_tryon' => ['Pedicure try-on', 'تجربة الباديكير', 'image', 2, ['gemini', 'openrouter', 'openai']],
        'full_look' => ['Full makeup look', 'إطلالة مكياج كاملة', 'image', 8, ['gemini', 'openrouter', 'openai', 'higgsfield']],
        'bridal_look' => ['Bridal look', 'إطلالة عروس', 'image', 8, ['gemini', 'openrouter', 'openai', 'higgsfield']],
        'consultant' => ['AI consultant', 'المستشارة الذكية', 'text', 1, ['anthropic', 'gemini', 'openai']],
        'marketing_banner' => ['Marketing banner', 'بانر تسويقي', 'image', 6, ['gemini', 'openrouter', 'openai']],
        'marketing_copy' => ['Marketing copy', 'نص تسويقي', 'text', 1, ['anthropic', 'openai', 'gemini']],
    ];

    protected function casts(): array
    {
        return ['preferred_providers' => 'array', 'is_active' => 'boolean'];
    }

    /** The configured price row for a task, or an unsaved default. */
    public static function for(string $task): self
    {
        $row = static::query()->where('task', $task)->first();
        if ($row) {
            return $row;
        }
        [$en, $ar, $capability, $credits, $preferred] = self::DEFAULTS[$task] ?? [$task, $task, 'image', 4, []];

        return new static(['task' => $task, 'name' => ['en' => $en, 'ar' => $ar], 'capability' => $capability, 'credits' => $credits, 'preferred_providers' => $preferred, 'is_active' => true]);
    }

    public static function seedDefaults(): void
    {
        $i = 0;
        foreach (self::DEFAULTS as $task => [$en, $ar, $capability, $credits, $preferred]) {
            static::query()->firstOrCreate(['task' => $task], [
                'name' => ['en' => $en, 'ar' => $ar], 'capability' => $capability, 'credits' => $credits,
                'preferred_providers' => $preferred, 'sort' => $i++,
            ]);
        }
    }
}

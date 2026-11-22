<?php

namespace App\Filament\Dashboard\Resources\Marketing\Banners\Pages;

use App\Domain\AIStudio\Contracts\GeneratesMarketingContent;
use App\Domain\Marketing\Models\Banner;
use App\Filament\Dashboard\Resources\Marketing\Banners\BannerResource;
use App\Filament\Marketing\MarketingAccess;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\Page;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Livewire\WithFileUploads;

/**
 * In-dashboard banner designer: luxury templates (story 9:16, post 1:1, WhatsApp 1.91:1), drag & drop text/logo/price,
 * brand colours applied automatically, Arabic typography, client-side PNG export, "Generate with AI".
 */
class BannerStudio extends Page
{
    use WithFileUploads;

    protected static string $resource = BannerResource::class;

    protected string $view = 'filament.marketing.banner-studio';

    public ?int $bannerId = null;

    public string $name = '';

    /** Exported PNG (uploaded from the canvas). */
    public $png = null;

    public static function canAccess(array $parameters = []): bool
    {
        return MarketingAccess::manage();
    }

    public function mount(int|string|null $record = null): void
    {
        if ($record) {
            $banner = Banner::findOrFail($record);
            $this->bannerId = $banner->id;
            $this->name = $banner->name;
        } else {
            $this->name = __('marketing.banners.new').' '.now(tenant()->timezone)->format('j M');
        }
    }

    public function getTitle(): string
    {
        return __('marketing.banners.studio');
    }

    public function getSubheading(): ?string
    {
        return __('marketing.banners.subtitle');
    }

    public function saveBanner(string $format, ?string $template, array $design): void
    {
        abort_unless(MarketingAccess::manage(), 403);
        $this->validate(['name' => ['required', 'string', 'max:120'], 'png' => ['nullable', 'image', 'max:8192']]);
        $format = array_key_exists($format, Banner::FORMATS) ? $format : 'post';

        $banner = $this->bannerId ? Banner::findOrFail($this->bannerId) : new Banner(['created_by' => auth()->id()]);
        $banner->fill(['name' => $this->name, 'format' => $format, 'template' => $template, 'design' => $design]);
        if ($this->png) {
            $path = $this->png->storeAs('banners/'.tenant()->id, Str::ulid().'.png', 'public');
            if ($banner->exported_path) {
                Storage::disk('public')->delete($banner->exported_path);
            }
            $banner->exported_path = $path;
            $this->png = null;
        }
        $banner->save();
        $this->bannerId = $banner->id;

        Notification::make()->success()->title(__('marketing.banners.saved'))->send();
    }

    /** @return array{image_url: ?string, headline: ?string, error: ?string} */
    public function generateWithAi(string $prompt, string $format): array
    {
        abort_unless(MarketingAccess::manage(), 403);
        $aspect = ['story' => '9:16', 'post' => '1:1', 'whatsapp' => '1.91:1'][$format] ?? '1:1';
        $ai = app(GeneratesMarketingContent::class);
        $brief = trim($prompt) ?: (tenant()->name.' luxury salon offer');
        $visual = $ai->bannerVisual($brief.' — palette '.tenant()->brand('primary').' and '.tenant()->brand('accent').', elegant, minimal, no text', $aspect);
        $copy = $ai->copy('Banner headline (max 5 words) for: '.$brief);

        if (! $visual['image_url'] && $visual['error']) {
            Notification::make()->warning()->title(__('marketing.banners.ai_failed', ['error' => $visual['error']]))->send();
        }

        return [
            'image_url' => $visual['image_url'] ?? null,
            'headline' => Str::limit((string) ($copy[app()->getLocale()] ?? $copy['en'] ?? ''), 60, ''),
            'error' => $visual['error'] ?? null,
        ];
    }

    protected function getViewData(): array
    {
        $t = tenant();
        $banner = $this->bannerId ? Banner::find($this->bannerId) : null;
        $locale = app()->getLocale();
        $samples = __('marketing.banners.samples');

        return [
            'config' => [
                'name' => Str::slug($this->name) ?: 'banner',
                'format' => $banner?->format ?? 'post',
                'template' => $banner?->template ?? 'editorial',
                'design' => $banner?->design,
                'formats' => Banner::FORMATS,
                'logoUrl' => $t->brand('logo_path') ? asset('storage/'.$t->brand('logo_path')) : null,
                'brand' => [
                    'primary' => $t->brand('primary'), 'primaryDark' => '#A88B4F', 'accent' => $t->brand('accent'),
                    'ink' => $t->brand('ink'), 'background' => $t->brand('background'),
                    'display' => $t->brand('font_display'), 'body' => $t->brand('font_body'),
                ],
                'copy' => [
                    'headline' => $locale === 'ar' ? $samples['headline_ar'] : $samples['headline'],
                    'subline' => $locale === 'ar' ? $samples['subline_ar'] : $samples['subline'],
                    'price' => $locale === 'ar' ? $samples['price_ar'] : $samples['price'],
                    'cta' => $locale === 'ar' ? $samples['cta_ar'] : $samples['cta'],
                    'headline_ar' => $samples['headline_ar'], 'subline_ar' => $samples['subline_ar'], 'price_ar' => $samples['price_ar'], 'cta_ar' => $samples['cta_ar'],
                ],
                'fonts' => array_values(array_unique([$t->brand('font_display'), $t->brand('font_body'), 'Playfair Display', 'Cormorant Garamond', 'Inter', 'IBM Plex Sans Arabic', 'Amiri', 'Tajawal'])),
            ],
            'exportedUrl' => $banner?->imageUrl(),
            'script' => file_get_contents(resource_path('js/marketing/banner-studio.js')),
        ];
    }
}

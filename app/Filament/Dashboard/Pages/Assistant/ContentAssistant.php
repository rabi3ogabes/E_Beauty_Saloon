<?php

namespace App\Filament\Dashboard\Pages\Assistant;

use App\Domain\Assistant\Actions\GenerateContent;
use App\Domain\Catalog\Models\Service;
use App\Domain\Insights\Support\InsightsGate;
use App\Domain\Insights\Support\SmartText;
use App\Filament\Dashboard\Pages\AIStudio\AIStudioWallet;
use App\Support\Exceptions\DomainException;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;

/** AI content assistant: service descriptions, offers and captions in English + Arabic (uses AI credits). */
class ContentAssistant extends Page
{
    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedPencilSquare;

    protected static ?string $slug = 'ai-studio/content';

    protected static ?int $navigationSort = 20;

    protected string $view = 'filament.dashboard.assistant.content';

    public ?array $data = ['kind' => 'service_description', 'tone' => 'luxury'];

    public ?array $result = null;

    public static function canAccess(): bool
    {
        return InsightsGate::ai();
    }

    public static function getNavigationGroup(): ?string
    {
        return __('salon.nav.ai_studio');
    }

    public static function getNavigationLabel(): string
    {
        return __('insights.content.nav');
    }

    public function getTitle(): string
    {
        return __('insights.content.title');
    }

    public function getSubheading(): ?string
    {
        return __('insights.content.subtitle', ['credits' => app(SmartText::class)->credits('content_assistant')]);
    }

    public function mount(): void
    {
        $this->form->fill($this->data);
    }

    public function form(Schema $schema): Schema
    {
        return $schema->statePath('data')->components([
            Section::make()->columns(2)->schema([
                Select::make('kind')->label(__('insights.content.kind'))->options(collect(GenerateContent::KINDS)->mapWithKeys(fn ($k) => [$k => __('insights.content.kinds.'.$k)]))->required()->native(false)->live(),
                Select::make('tone')->label(__('insights.content.tone'))->options(collect(['luxury', 'warm', 'playful', 'minimal'])->mapWithKeys(fn ($k) => [$k => __('insights.content.tones.'.$k)]))->required()->native(false),
                Select::make('service_id')->label(__('insights.content.service'))->options(fn () => Service::query()->orderBy('sort')->get()->mapWithKeys(fn ($s) => [$s->id => (string) $s->name]))->searchable()->columnSpanFull(),
                Textarea::make('brief')->label(__('insights.content.brief'))->placeholder(__('insights.content.brief_placeholder'))->rows(3)->maxLength(600)->required()->columnSpanFull(),
            ]),
        ]);
    }

    public function generate(): void
    {
        $data = $this->form->getState();
        try {
            $this->result = app(GenerateContent::class)->handle($data['kind'], (string) $data['brief'], (string) ($data['tone'] ?? 'luxury'),
                ! empty($data['service_id']) ? Service::find($data['service_id']) : null);
        } catch (DomainException $e) {
            Notification::make()->danger()->title($e->getMessage())->send();
        }
    }

    public function applyToService(): void
    {
        abort_unless(auth()->user()->can('catalog.manage') && $this->result && ! empty($this->data['service_id']) && $this->data['kind'] === 'service_description', 403);
        $service = Service::findOrFail($this->data['service_id']);
        $service->setTranslation('description', 'en', $this->result['en']);
        if ($this->result['ar'] !== '') {
            $service->setTranslation('description', 'ar', $this->result['ar']);
        }
        $service->save();
        Notification::make()->success()->title(__('insights.content.applied'))->send();
    }

    protected function getHeaderActions(): array
    {
        return [
            Action::make('wallet')->label(__('insights.content.wallet'))->icon(Heroicon::OutlinedWallet)->color('gray')
                ->url(fn () => AIStudioWallet::canAccess() ? AIStudioWallet::getUrl() : null)
                ->visible(fn () => AIStudioWallet::canAccess()),
        ];
    }
}

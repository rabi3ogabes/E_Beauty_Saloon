<?php

namespace App\Filament\Dashboard\Pages\Assistant;

use App\Domain\Assistant\Support\AssistantSettings;
use App\Domain\Insights\Support\InsightsGate;
use App\Filament\Dashboard\Support\SettingsPage;
use BackedEnum;
use Filament\Forms\Components\TagsInput;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Notifications\Notification;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;

/** Owner switch, hours, FAQ and handoff rules for the WhatsApp AI receptionist. */
class AssistantSettingsPage extends SettingsPage
{
    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedCpuChip;

    protected static ?int $navigationSort = 41;

    protected static ?string $slug = 'settings/receptionist';

    public static function getNavigationGroup(): ?string
    {
        return __('salon.nav.settings');
    }

    public static function getNavigationLabel(): string
    {
        return __('insights.assistant.settings.nav');
    }

    public function getTitle(): string
    {
        return __('insights.assistant.settings.title');
    }

    public function getSubheading(): ?string
    {
        return __('insights.assistant.settings.subtitle');
    }

    public static function canAccess(): bool
    {
        return InsightsGate::settings();
    }

    protected function fillData(): array
    {
        return AssistantSettings::all();
    }

    public function form(Schema $schema): Schema
    {
        $t = fn (string $k) => __('insights.assistant.settings.'.$k);

        return $schema->components([
            Section::make($t('duty'))->description($t('duty_help'))->icon(Heroicon::OutlinedPower)->columns(2)->schema([
                Toggle::make('enabled')->label($t('enabled'))->helperText($t('enabled_help'))->columnSpanFull(),
                TextInput::make('hours_from')->label($t('hours_from'))->type('time')->required(),
                TextInput::make('hours_to')->label($t('hours_to'))->helperText($t('hours_help'))->regex('/^([01]\d|2[0-4]):[0-5]\d$/')->required(),
            ]),
            Section::make($t('knowledge'))->description($t('knowledge_help'))->icon(Heroicon::OutlinedBookOpen)->schema([
                TextInput::make('tone')->label($t('tone'))->maxLength(120),
                Textarea::make('faq')->label($t('faq'))->placeholder($t('faq_placeholder'))->rows(6)->maxLength(4000),
            ]),
            Section::make($t('handoff'))->description($t('handoff_help'))->icon(Heroicon::OutlinedUserCircle)->columns(2)->schema([
                TagsInput::make('handoff_keywords')->label($t('keywords'))->columnSpanFull(),
                TextInput::make('min_confidence')->label($t('min_confidence'))->numeric()->minValue(0)->maxValue(1)->step('0.05')->required(),
            ]),
        ]);
    }

    public function save(): void
    {
        abort_if($this->isReadOnly() || ! static::canAccess(), 403);
        $data = $this->form->getState();
        $data['enabled'] = (bool) $data['enabled'];
        $data['min_confidence'] = (float) $data['min_confidence'];
        $data['handoff_keywords'] = array_values(array_filter(array_map('trim', (array) ($data['handoff_keywords'] ?? []))));
        AssistantSettings::put($data);
        activity()->performedOn(tenant())->withProperties(['enabled' => $data['enabled']])->log('assistant.settings_updated');
        Notification::make()->success()->title(__('insights.settings.saved'))->send();
    }
}

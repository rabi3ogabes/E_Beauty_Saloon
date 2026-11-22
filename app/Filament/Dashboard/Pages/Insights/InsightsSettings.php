<?php

namespace App\Filament\Dashboard\Pages\Insights;

use App\Domain\Branches\Models\Branch;
use App\Domain\Insights\Support\InsightSettings;
use App\Domain\Insights\Support\InsightsGate;
use App\Filament\Dashboard\Support\SettingsPage;
use App\Filament\Support\Money;
use BackedEnum;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Notifications\Notification;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;

/** Smart layer settings: morning report, revenue targets, owner alerts, client sharing, consultation forms, AI copy. */
class InsightsSettings extends SettingsPage
{
    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedLightBulb;

    protected static ?int $navigationSort = 40;

    protected static ?string $slug = 'settings/insights';

    public static function getNavigationGroup(): ?string
    {
        return __('salon.nav.settings');
    }

    public static function getNavigationLabel(): string
    {
        return __('insights.settings.nav');
    }

    public function getTitle(): string
    {
        return __('insights.settings.title');
    }

    public function getSubheading(): ?string
    {
        return __('insights.settings.subtitle');
    }

    public static function canAccess(): bool
    {
        return InsightsGate::settings();
    }

    protected function fillData(): array
    {
        $s = InsightSettings::all();
        $s['targets']['daily'] = collect(Branch::query()->pluck('id'))->mapWithKeys(fn ($id) => ['b'.$id => $s['targets']['daily'][$id] ?? null])->all();

        return $s;
    }

    public function form(Schema $schema): Schema
    {
        $t = fn (string $k) => __('insights.settings.'.$k);

        return $schema->columns(2)->components([
            Section::make($t('morning'))->description($t('morning_help'))->icon(Heroicon::OutlinedSun)->columns(2)->schema([
                Toggle::make('morning_report.enabled')->label($t('morning_enabled'))->columnSpanFull(),
                Toggle::make('morning_report.email')->label($t('by_email')),
                Toggle::make('morning_report.whatsapp')->label($t('by_whatsapp')),
                TextInput::make('morning_report.time')->label($t('morning_time'))->type('time')->required(),
            ]),
            Section::make($t('targets'))->description($t('targets_help'))->icon(Heroicon::OutlinedFlag)->columns(2)->schema(array_merge(
                [Money::input('targets.daily_default')->label($t('target_default'))->columnSpanFull()],
                Branch::query()->orderBy('sort')->get()->map(fn (Branch $b) => Money::input('targets.daily.b'.$b->id)->label($t('target_branch').' · '.$b->name))->all(),
            )),
            Section::make($t('alerts'))->description($t('alerts_help'))->icon(Heroicon::OutlinedBellAlert)->columns(2)->schema([
                Toggle::make('alerts.big_sale')->label(__('insights.alerts.types.big_sale')),
                Money::input('alerts.big_sale_threshold')->label($t('big_sale_threshold')),
                Toggle::make('alerts.vip_checkin')->label(__('insights.alerts.types.vip_checkin')),
                Toggle::make('alerts.bad_review')->label(__('insights.alerts.types.bad_review')),
                Toggle::make('alerts.low_stock')->label(__('insights.alerts.types.low_stock')),
                Toggle::make('alerts.failed_payment')->label(__('insights.alerts.types.failed_payment')),
                Toggle::make('alerts.whatsapp')->label($t('alerts_whatsapp')),
                TextInput::make('alerts.whatsapp_to')->label($t('alerts_whatsapp_to'))->tel()->maxLength(20)->placeholder('+974…'),
            ]),
            Section::make($t('clients'))->description($t('clients_help'))->icon(Heroicon::OutlinedUsers)->columns(2)->schema([
                Toggle::make('client_sharing')->label($t('client_sharing'))->helperText($t('client_sharing_help'))->columnSpanFull(),
                Toggle::make('consultation.auto_send')->label($t('consultation_auto'))->helperText($t('consultation_auto_help')),
                TextInput::make('consultation.valid_months')->label($t('consultation_valid'))->integer()->minValue(1)->maxValue(60)->suffix($t('months')),
                Toggle::make('ai_copy')->label($t('ai_copy'))->helperText($t('ai_copy_help'))->columnSpanFull(),
            ]),
        ]);
    }

    public function save(): void
    {
        abort_if($this->isReadOnly() || ! static::canAccess(), 403);
        $data = $this->form->getState();
        $data['targets']['daily'] = collect($data['targets']['daily'] ?? [])->mapWithKeys(fn ($v, $k) => [(int) ltrim((string) $k, 'b') => $v === null ? null : (int) $v])->all();
        $data['consultation']['valid_months'] = (int) ($data['consultation']['valid_months'] ?? 12);

        $settings = tenant()->settings ?? [];
        $settings['insights'] = array_replace_recursive(InsightSettings::DEFAULTS, $data);
        tenant()->forceFill(['settings' => $settings])->save();
        activity()->performedOn(tenant())->log('insights.settings_updated');

        Notification::make()->success()->title(__('insights.settings.saved'))->send();
    }
}

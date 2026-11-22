<?php

namespace App\Filament\Dashboard\Resources\Marketing\Campaigns;

use App\Domain\Marketing\Actions\EstimateCampaignCost;
use App\Domain\Marketing\Models\Banner;
use App\Domain\Marketing\Models\Campaign;
use App\Domain\Marketing\Models\Segment;
use App\Domain\Marketing\Models\WhatsappTemplate;
use App\Filament\Dashboard\Resources\Marketing\Campaigns\Pages\CreateCampaign;
use App\Filament\Dashboard\Resources\Marketing\Campaigns\Pages\ListCampaigns;
use App\Filament\Dashboard\Resources\Marketing\Campaigns\Pages\ViewCampaign;
use App\Filament\Marketing\GatedResource;
use App\Filament\Marketing\MarketingAccess;
use BackedEnum;
use Filament\Actions\ViewAction;
use Filament\Forms\Components\DateTimePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Forms\Components\ToggleButtons;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Text;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\Wizard\Step;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;

class CampaignResource extends Resource
{
    use GatedResource;

    protected static ?string $model = Campaign::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedMegaphone;

    protected static ?int $navigationSort = 10;

    public static function getModelLabel(): string
    {
        return __('marketing.campaigns.label');
    }

    public static function getPluralModelLabel(): string
    {
        return __('marketing.campaigns.plural');
    }

    public static function canCreate(): bool
    {
        return MarketingAccess::manage();
    }

    public static function canEdit(Model $record): bool
    {
        return false; // campaigns are created with the wizard and then only launched / cancelled
    }

    public static function channelOptions(): array
    {
        return collect(['whatsapp', 'sms', 'email', 'push'])->mapWithKeys(fn ($c) => [$c => __('marketing.channels.'.$c)])->all();
    }

    /** @return list<Step> */
    public static function wizardSteps(): array
    {
        return [
            Step::make(__('marketing.campaigns.steps.audience'))->icon('heroicon-o-user-group')->schema([
                TextInput::make('name')->label(__('marketing.common.name'))->required()->maxLength(120),
                Select::make('segment_id')->label(__('marketing.campaigns.segment'))->required()->live()
                    ->options(fn () => Segment::orderBy('name')->pluck('name', 'id'))
                    ->helperText(fn (Get $get) => ($s = Segment::find($get('segment_id'))) ? trans_choice('marketing.segments.clients', $c = $s->refreshCount(), ['count' => number_format($c)]) : null),
            ]),
            Step::make(__('marketing.campaigns.steps.channels'))->icon('heroicon-o-chat-bubble-left-right')->columns(2)->schema([
                ToggleButtons::make('primary_channel')->label(__('marketing.campaigns.primary_channel'))->options(static::channelOptions())->inline()->required()->default('whatsapp')->live(),
                ToggleButtons::make('fallback_channel')->label(__('marketing.campaigns.fallback'))->inline()->live()
                    ->options(fn (Get $get) => ['' => __('marketing.common.none')] + collect(static::channelOptions())->except($get('primary_channel'))->all())->default('sms'),
                Text::make(__('marketing.campaigns.channels_help'))->columnSpanFull(),
                Select::make('whatsapp_template_id')->label(__('marketing.campaigns.template'))->helperText(__('marketing.campaigns.template_help'))
                    ->options(fn () => WhatsappTemplate::where('status', 'approved')->orderBy('name')->get()->mapWithKeys(fn ($t) => [$t->id => $t->name.' · '.$t->language]))
                    ->visible(fn (Get $get) => in_array('whatsapp', [$get('primary_channel'), $get('fallback_channel')], true))->columnSpanFull(),
            ]),
            Step::make(__('marketing.campaigns.steps.content'))->icon('heroicon-o-pencil-square')->schema([
                Grid::make(['default' => 1, 'md' => 2])->schema([
                    TextInput::make('content.en.subject')->label(__('marketing.campaigns.subject').' · English')->maxLength(150),
                    TextInput::make('content.ar.subject')->label(__('marketing.campaigns.subject').' · العربية')->maxLength(150)->extraInputAttributes(['dir' => 'rtl']),
                    Textarea::make('content.en.body')->label(__('marketing.campaigns.body').' · English')->rows(5)->required()->maxLength(1000)
                        ->default(fn () => "Hi {first_name} ✦\n\n{link}"),
                    Textarea::make('content.ar.body')->label(__('marketing.campaigns.body').' · العربية')->rows(5)->maxLength(1000)->extraInputAttributes(['dir' => 'rtl']),
                ]),
                Text::make(__('marketing.campaigns.body_help')),
                Grid::make(['default' => 1, 'md' => 2])->schema([
                    Select::make('banner_id')->label(__('marketing.campaigns.banner'))->options(fn () => Banner::whereNotNull('exported_path')->latest()->pluck('name', 'id')),
                    TextInput::make('media_url')->label(__('marketing.campaigns.media_url'))->url()->maxLength(1000),
                    TextInput::make('cta_url')->label(__('marketing.campaigns.cta_url'))->helperText(__('marketing.campaigns.cta_url_help'))->placeholder('/book')->maxLength(1000),
                    TextInput::make('cta_label')->label(__('marketing.campaigns.cta_label'))->placeholder(__('marketing.campaigns.default_cta'))->maxLength(25),
                ]),
            ]),
            Step::make(__('marketing.campaigns.steps.ab'))->icon('heroicon-o-beaker')->schema([
                Toggle::make('ab_enabled')->label(__('marketing.campaigns.ab_enabled'))->helperText(__('marketing.campaigns.ab_help'))->live(),
                Grid::make(3)->visible(fn (Get $get) => (bool) $get('ab_enabled'))->schema([
                    TextInput::make('ab_test_percent')->label(__('marketing.campaigns.ab_test_percent'))->numeric()->minValue(5)->maxValue(50)->default(20)->suffix('%'),
                    Select::make('ab_metric')->label(__('marketing.campaigns.ab_metric'))->options(['click' => __('marketing.campaigns.ab_metrics.click'), 'booking' => __('marketing.campaigns.ab_metrics.booking')])->default('click'),
                    TextInput::make('ab_wait_hours')->label(__('marketing.campaigns.ab_wait_hours'))->numeric()->minValue(1)->maxValue(168)->default(4),
                    TextInput::make('variant_b.en.subject')->label(__('marketing.campaigns.variant_b_subject'))->columnSpanFull(),
                    Textarea::make('variant_b.en.body')->label(__('marketing.campaigns.variant_b').' · English')->rows(4)->columnSpan(['default' => 3, 'md' => 3])
                        ->required(fn (Get $get) => (bool) $get('ab_enabled')),
                    Textarea::make('variant_b.ar.body')->label(__('marketing.campaigns.variant_b').' · العربية')->rows(4)->columnSpanFull()->extraInputAttributes(['dir' => 'rtl']),
                ]),
            ]),
            Step::make(__('marketing.campaigns.steps.schedule'))->icon('heroicon-o-paper-airplane')->schema([
                ToggleButtons::make('when')->label(__('marketing.campaigns.when'))->inline()->live()->default('now')
                    ->options(['now' => __('marketing.campaigns.send_now'), 'later' => __('marketing.campaigns.schedule_later')]),
                DateTimePicker::make('scheduled_at')->label(__('marketing.campaigns.scheduled_at'))->seconds(false)->minDate(now())
                    ->timezone(tenant()?->timezone ?? 'UTC')
                    ->visible(fn (Get $get) => $get('when') === 'later')->required(fn (Get $get) => $get('when') === 'later'),
                Section::make(__('marketing.campaigns.estimate'))->schema([
                    Text::make(fn (Get $get) => static::estimateLine($get('segment_id'), array_values(array_filter([$get('primary_channel'), $get('fallback_channel')])))),
                ]),
            ]),
        ];
    }

    public static function estimateLine(?int $segmentId, array $channels): string
    {
        $e = app(EstimateCampaignCost::class)->forSegment(Segment::find($segmentId), $channels);

        return __('marketing.campaigns.estimate_line', [
            'reachable' => number_format($e['reachable']), 'audience' => number_format($e['audience']), 'skipped' => number_format($e['skipped']), 'cost' => money($e['cost']),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table->defaultSort('created_at', 'desc')
            ->modifyQueryUsing(fn ($query) => $query->with('segment')->withSum('messages', 'revenue')->withCount(['messages as clicks_count' => fn ($q) => $q->whereNotNull('clicked_at')]))
            ->columns([
                TextColumn::make('name')->label(__('marketing.common.name'))->weight('semibold')->searchable()
                    ->description(fn (Campaign $r) => $r->segment?->name),
                TextColumn::make('status')->label(__('marketing.common.status'))->badge()
                    ->formatStateUsing(fn ($state) => __('marketing.campaigns.statuses.'.$state))
                    ->color(fn ($state) => match ($state) {
                        'sent' => 'success', 'sending', 'testing' => 'info', 'scheduled' => 'warning', 'failed', 'cancelled' => 'danger', default => 'gray'
                    }),
                TextColumn::make('channels')->label(__('marketing.campaigns.channels'))->badge()->formatStateUsing(fn ($state) => __('marketing.channels.'.$state)),
                TextColumn::make('recipients_count')->label(__('marketing.campaigns.recipients'))->numeric()->alignEnd(),
                TextColumn::make('clicks_count')->label(__('marketing.campaigns.clicks'))->numeric()->alignEnd(),
                TextColumn::make('messages_sum_revenue')->label(__('marketing.campaigns.revenue'))->formatStateUsing(fn ($state) => money((int) $state))->alignEnd(),
                TextColumn::make('started_at')->label(__('marketing.campaigns.sent_at'))->since()->placeholder('—')->sortable(),
            ])
            ->filters([SelectFilter::make('status')->label(__('marketing.common.status'))->options(collect(Campaign::STATUSES)->mapWithKeys(fn ($s) => [$s => __('marketing.campaigns.statuses.'.$s)]))])
            ->recordActions([ViewAction::make()->label(__('marketing.campaigns.report'))->icon('heroicon-o-chart-bar')])
            ->emptyStateHeading(__('marketing.campaigns.empty'))->emptyStateDescription(__('marketing.campaigns.empty_help'))->emptyStateIcon('heroicon-o-megaphone');
    }

    public static function getPages(): array
    {
        return [
            'index' => ListCampaigns::route('/'),
            'create' => CreateCampaign::route('/create'),
            'view' => ViewCampaign::route('/{record}'),
        ];
    }
}

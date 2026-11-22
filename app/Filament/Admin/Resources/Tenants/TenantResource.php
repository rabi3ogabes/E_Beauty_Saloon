<?php

namespace App\Filament\Admin\Resources\Tenants;

use App\Domain\Billing\Actions\CalculatePrice;
use App\Domain\Billing\Models\Module;
use App\Domain\Billing\Models\Plan;
use App\Domain\Tenancy\Actions\StartImpersonation;
use App\Domain\Tenancy\Enums\TenantStatus;
use App\Domain\Tenancy\Models\Tenant;
use App\Filament\Admin\Resources\Tenants\Pages\CreateTenant;
use App\Filament\Admin\Resources\Tenants\Pages\EditTenant;
use App\Filament\Admin\Resources\Tenants\Pages\ListTenants;
use App\Filament\Admin\Resources\Tenants\Pages\ViewTenant;
use App\Filament\Admin\Resources\Tenants\RelationManagers\DomainsRelationManager;
use App\Filament\Admin\Resources\Tenants\RelationManagers\InvoicesRelationManager;
use App\Filament\Admin\Resources\Tenants\RelationManagers\SubscriptionsRelationManager;
use App\Filament\Admin\Resources\Tenants\RelationManagers\UsersRelationManager;
use App\Support\Exceptions\DomainException;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Actions\ActionGroup;
use Filament\Actions\ViewAction;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Infolists\Components\TextEntry;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\HtmlString;
use Illuminate\Support\Str;

class TenantResource extends Resource
{
    protected static ?string $model = Tenant::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedBuildingStorefront;

    protected static ?int $navigationSort = 1;

    protected static ?string $recordTitleAttribute = 'name';

    public static function getNavigationGroup(): ?string
    {
        return __('salon.nav.salons');
    }

    public static function getModelLabel(): string
    {
        return __('admin.tenant.label');
    }

    public static function getPluralModelLabel(): string
    {
        return __('admin.tenant.plural');
    }

    public static function getNavigationBadge(): ?string
    {
        return (string) Tenant::where('status', TenantStatus::Trial)->count() ?: null;
    }

    public static function getNavigationBadgeTooltip(): ?string
    {
        return __('admin.tenant.trials_badge');
    }

    public static function canViewAny(): bool
    {
        return auth()->user()?->can('platform.tenants.view') ?? false;
    }

    public static function canCreate(): bool
    {
        return auth()->user()?->can('platform.tenants.manage') ?? false;
    }

    public static function canEdit($record): bool
    {
        return auth()->user()?->can('platform.tenants.manage') ?? false;
    }

    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            Section::make(__('admin.tenant.business'))->columns(2)->schema([
                TextInput::make('name')->label(__('fields.salon_name'))->required()->maxLength(120),
                TextInput::make('legal_name')->label(__('admin.tenant.legal_name'))->maxLength(160),
                TextInput::make('email')->label(__('fields.email'))->email(),
                TextInput::make('phone')->label(__('fields.phone'))->tel(),
                Select::make('country')->label(__('fields.country'))->options(['QA' => 'Qatar', 'AE' => 'UAE', 'SA' => 'Saudi Arabia', 'KW' => 'Kuwait', 'BH' => 'Bahrain', 'OM' => 'Oman'])->required(),
                Select::make('currency')->label(__('fields.currency'))->options(['QAR' => 'QAR', 'AED' => 'AED', 'SAR' => 'SAR', 'KWD' => 'KWD', 'BHD' => 'BHD', 'OMR' => 'OMR', 'USD' => 'USD'])->required(),
                Select::make('timezone')->label(__('fields.timezone'))->options(collect(['Asia/Qatar', 'Asia/Dubai', 'Asia/Riyadh', 'Asia/Kuwait', 'Asia/Bahrain', 'Asia/Muscat'])->mapWithKeys(fn ($z) => [$z => $z]))->required(),
                Select::make('default_locale')->label(__('fields.locale'))->options(config('salon.locales'))->required(),
                TextInput::make('tax_number')->label(__('admin.tenant.tax_number')),
            ]),
        ]);
    }

    public static function infolist(Schema $schema): Schema
    {
        return $schema->components([
            Grid::make(['default' => 1, 'lg' => 3])->schema([
                Section::make(__('admin.tenant.overview'))->columnSpan(2)->columns(3)->schema([
                    TextEntry::make('name')->label(__('fields.salon_name'))->weight('semibold')->size('lg'),
                    TextEntry::make('status')->label(__('fields.status'))->badge(),
                    TextEntry::make('primaryDomain.domain')->label(__('fields.domain'))
                        ->url(fn (Tenant $r) => $r->url(), shouldOpenInNewTab: true)->color('primary')->icon(Heroicon::OutlinedGlobeAlt),
                    TextEntry::make('owner.name')->label(__('fields.owner')),
                    TextEntry::make('owner.email')->label(__('fields.owner_email'))->copyable(),
                    TextEntry::make('phone')->label(__('fields.phone'))->placeholder('—'),
                    TextEntry::make('currency')->label(__('fields.currency')),
                    TextEntry::make('timezone')->label(__('fields.timezone')),
                    TextEntry::make('created_at')->label(__('salon.ui.created'))->since(),
                ]),
                Section::make(__('admin.tenant.subscription'))->columnSpan(1)->schema([
                    TextEntry::make('currentSubscription.plan.name')->label(__('fields.plan'))->placeholder(__('admin.tenant.no_plan'))->weight('semibold'),
                    TextEntry::make('currentSubscription.status')->label(__('fields.status'))->badge(),
                    TextEntry::make('currentSubscription.branches_count')->label(__('fields.branches')),
                    TextEntry::make('currentSubscription.modules')->label(__('fields.modules'))->badge()
                        ->formatStateUsing(fn ($state) => __('salon.modules.'.$state)),
                    TextEntry::make('currentSubscription.ends_at')->label(__('fields.ends_at'))->date()->helperText(fn (Tenant $r) => $r->currentSubscription?->ends_at?->diffForHumans()),
                ]),
            ])->columnSpanFull(),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn (Builder $query) => $query->with(['currentSubscription.plan', 'primaryDomain', 'owner']))
            ->defaultSort('created_at', 'desc')
            ->columns([
                TextColumn::make('name')->label(__('fields.salon_name'))->searchable()->sortable()->weight('semibold')
                    ->description(fn (Tenant $r) => $r->primaryDomain?->domain),
                TextColumn::make('owner.name')->label(__('fields.owner'))->searchable()->description(fn (Tenant $r) => $r->owner?->email)->toggleable(),
                TextColumn::make('currentSubscription.plan.name')->label(__('fields.plan'))->badge()->color('gray')->placeholder('—'),
                TextColumn::make('status')->label(__('fields.status'))->badge()->sortable(),
                TextColumn::make('currentSubscription.branches_count')->label(__('fields.branches'))->alignCenter(),
                TextColumn::make('mrr')->label('MRR')->state(fn (Tenant $r) => $r->currentSubscription
                    ? money((int) round(($r->currentSubscription->price_snapshot['total'] ?? 0) / max(1, $r->currentSubscription->cycle_months)), $r->currency)
                    : '—')->alignEnd(),
                TextColumn::make('currentSubscription.ends_at')->label(__('fields.ends_at'))->date()->sortable()
                    ->color(fn (Tenant $r) => $r->currentSubscription?->ends_at?->lt(now()->addDays(7)) ? 'warning' : null),
                TextColumn::make('created_at')->label(__('salon.ui.created'))->since()->sortable()->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                SelectFilter::make('status')->label(__('fields.status'))->options(TenantStatus::class),
                \Filament\Tables\Filters\Filter::make('plan')
                    ->schema([\Filament\Forms\Components\Select::make('plan_id')->label(__('fields.plan'))->options(fn () => Plan::all()->pluck('name', 'id'))])
                    ->query(fn (Builder $query, array $data) => filled($data['plan_id'] ?? null)
                        ? $query->whereHas('subscriptions', fn ($s) => $s->where('plan_id', $data['plan_id'])->whereIn('status', ['active', 'trialing', 'grace', 'past_due']))
                        : $query),
            ])
            ->recordActions([
                static::impersonateAction(),
                ViewAction::make(),
                ActionGroup::make(static::lifecycleActions()),
            ])
            ->emptyStateHeading(__('admin.tenant.empty'))
            ->emptyStateDescription(__('admin.tenant.empty_body'));
    }

    public static function impersonateAction(): Action
    {
        return Action::make('impersonate')
            ->label(__('salon.impersonation.login_as_owner'))
            ->icon(Heroicon::OutlinedArrowRightEndOnRectangle)
            ->color('primary')
            ->visible(fn () => auth()->user()?->can('platform.tenants.impersonate'))
            ->modalHeading(fn (Tenant $record) => __('admin.tenant.impersonate_heading', ['salon' => $record->name]))
            ->modalDescription(__('admin.tenant.impersonate_body'))
            ->schema([
                Textarea::make('reason')->label(__('salon.impersonation.reason'))->required()->minLength(5)->rows(2)
                    ->placeholder(__('admin.tenant.impersonate_placeholder')),
            ])
            ->action(function (Tenant $record, array $data, $livewire) {
                try {
                    $url = app(StartImpersonation::class)->handle(auth()->user(), $record, $data['reason']);
                } catch (DomainException $e) {
                    Notification::make()->danger()->title($e->getMessage())->send();

                    return;
                }
                $livewire->js('window.open('.json_encode($url).', "_blank")');
            });
    }

    /** @return list<Action> */
    public static function lifecycleActions(): array
    {
        return [
            Action::make('extendTrial')->label(__('admin.tenant.extend_trial'))->icon(Heroicon::OutlinedClock)
                ->visible(fn (Tenant $r) => $r->status === TenantStatus::Trial)
                ->schema([TextInput::make('days')->numeric()->minValue(1)->maxValue(90)->default(7)->required()->suffix(__('admin.days'))])
                ->action(function (Tenant $record, array $data) {
                    $sub = $record->currentSubscription;
                    $until = ($sub?->ends_at ?? now())->copy()->addDays((int) $data['days']);
                    $sub?->update(['ends_at' => $until]);
                    $record->update(['trial_ends_at' => $until]);
                    activity()->performedOn($record)->withProperties($data)->log('tenant.trial_extended');
                    Notification::make()->success()->title(__('admin.tenant.trial_extended', ['date' => $until->toFormattedDateString()]))->send();
                }),
            Action::make('suspend')->label(__('admin.tenant.suspend'))->icon(Heroicon::OutlinedPauseCircle)->color('danger')
                ->visible(fn (Tenant $r) => $r->status !== TenantStatus::Suspended)
                ->requiresConfirmation()->modalDescription(__('admin.tenant.suspend_body'))
                ->schema([Textarea::make('reason')->label(__('fields.reason'))->required()])
                ->action(function (Tenant $record, array $data) {
                    $record->update(['status' => TenantStatus::Suspended]);
                    $record->currentSubscription?->update(['status' => 'suspended']);
                    activity()->performedOn($record)->withProperties($data)->log('tenant.suspended');
                    Notification::make()->success()->title(__('admin.tenant.suspended'))->send();
                }),
            Action::make('reactivate')->label(__('admin.tenant.reactivate'))->icon(Heroicon::OutlinedPlayCircle)->color('success')
                ->visible(fn (Tenant $r) => in_array($r->status, [TenantStatus::Suspended, TenantStatus::Grace, TenantStatus::Cancelled], true))
                ->requiresConfirmation()
                ->action(function (Tenant $record) {
                    $sub = $record->subscriptions()->latest('id')->first();
                    $record->update(['status' => TenantStatus::Active, 'grace_ends_at' => null]);
                    $sub?->update(['status' => 'active', 'ends_at' => $sub->ends_at?->isPast() ? now()->addDays(7) : $sub->ends_at]);
                    activity()->performedOn($record)->log('tenant.reactivated');
                    Notification::make()->success()->title(__('admin.tenant.reactivated'))->send();
                }),
        ];
    }

    /** Live quote markup used by the create & change-plan forms. */
    public static function quotePreview(?int $planId, ?int $cycle, ?int $branches, array $modules, ?string $coupon = null): HtmlString
    {
        if (! $planId || ! $cycle) {
            return new HtmlString('<p class="text-sm text-gray-500">'.e(__('admin.tenant.quote_hint')).'</p>');
        }
        try {
            $quote = app(CalculatePrice::class)->handle(Plan::findOrFail($planId), (int) $cycle, max(1, (int) $branches), $modules, $coupon ?: null);
        } catch (DomainException $e) {
            return new HtmlString('<p class="text-sm text-danger-600">'.e($e->getMessage()).'</p>');
        }

        return new HtmlString(view('filament.admin.quote', ['quote' => $quote])->render());
    }

    public static function moduleOptions(?int $planId): array
    {
        $included = $planId ? (Plan::find($planId)?->included_modules ?? []) : [];

        return Module::where('is_active', true)->orderBy('sort')->get()
            ->reject(fn ($m) => in_array($m->key, $included, true))
            ->mapWithKeys(fn ($m) => [$m->key => $m->name])->all();
    }

    public static function getRelations(): array
    {
        return [
            SubscriptionsRelationManager::class,
            InvoicesRelationManager::class,
            DomainsRelationManager::class,
            UsersRelationManager::class,
        ];
    }

    public static function getPages(): array
    {
        return [
            'index' => ListTenants::route('/'),
            'create' => CreateTenant::route('/create'),
            'view' => ViewTenant::route('/{record}'),
            'edit' => EditTenant::route('/{record}/edit'),
        ];
    }

    public static function getGloballySearchableAttributes(): array
    {
        return ['name', 'slug', 'email', 'phone'];
    }

    public static function slugFor(string $name): string
    {
        return Str::slug($name);
    }
}

<?php

namespace App\Filament\Dashboard\Resources\Clients;

use App\Domain\CRM\Models\Client;
use App\Domain\CRM\Models\Tag;
use App\Domain\Team\Models\Staff;
use App\Filament\Dashboard\Concerns\WorkspaceResource;
use App\Filament\Dashboard\Resources\Clients\Pages\CreateClient;
use App\Filament\Dashboard\Resources\Clients\Pages\EditClient;
use App\Filament\Dashboard\Resources\Clients\Pages\ListClients;
use App\Filament\Dashboard\Resources\Clients\Pages\ViewClient;
use App\Filament\Dashboard\Resources\Stock\BranchStockResource;
use BackedEnum;
use Closure;
use Filament\Actions\EditAction;
use Filament\Actions\ViewAction;
use Filament\Forms\Components\ColorPicker;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

class ClientResource extends Resource
{
    use WorkspaceResource;

    protected static ?string $model = Client::class;

    protected static string $viewPermission = 'clients.view';

    protected static string $managePermission = 'clients.manage';

    protected static string $navGroup = 'clients';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedUsers;

    protected static ?int $navigationSort = 1;

    protected static ?string $recordTitleAttribute = 'name';

    public static function getModelLabel(): string
    {
        return __('workspace.clients.label');
    }

    public static function getPluralModelLabel(): string
    {
        return __('workspace.clients.plural');
    }

    /** Lifetime value is cross-branch revenue: only unrestricted users with revenue access see it. */
    public static function canSeeRevenue(): bool
    {
        $user = auth()->user();

        return $user && $user->restrictedBranchIds() === null && $user->can('reports.revenue');
    }

    public static function canSeePrivate(): bool
    {
        return (bool) auth()->user()?->can('clients.private_notes');
    }

    public const SOURCES = ['walk_in', 'website', 'app', 'instagram', 'tiktok', 'referral', 'whatsapp', 'google', 'import'];

    public static function sourceOptions(): array
    {
        return collect(self::SOURCES)->mapWithKeys(fn ($s) => [$s => __('workspace.clients.sources.'.$s)])->all();
    }

    public static function form(Schema $schema): Schema
    {
        return $schema->columns(3)->components([
            Grid::make(1)->columnSpan(['default' => 3, 'lg' => 2])->schema([
                Section::make(__('workspace.clients.profile'))->columns(2)->schema([
                    TextInput::make('name')->label(__('fields.name'))->required()->maxLength(160),
                    TextInput::make('phone')->label(__('fields.phone'))->tel()->required()->maxLength(20)->placeholder('+974 5555 1234')
                        ->helperText(__('workspace.clients.phone_help'))
                        ->dehydrateStateUsing(fn ($state) => Client::normalizePhone((string) $state))
                        ->rules([fn ($record) => function (string $attribute, $value, Closure $fail) use ($record) {
                            $phone = Client::normalizePhone((string) $value);
                            if (! preg_match('/^\+[1-9]\d{6,14}$/', $phone)) {
                                $fail(__('workspace.clients.phone_invalid'));
                            } elseif (Client::withTrashed()->where('phone', $phone)->when($record, fn ($q) => $q->whereKeyNot($record->id))->exists()) {
                                $fail(__('workspace.clients.phone_taken'));
                            }
                        }]),
                    TextInput::make('email')->label(__('fields.email'))->email(),
                    Select::make('gender')->label(__('workspace.common.gender'))->native(false)
                        ->options(['female' => __('workspace.genders.female'), 'male' => __('workspace.genders.male'), 'other' => __('workspace.genders.other')]),
                    DatePicker::make('birthday')->label(__('workspace.clients.birthday'))->native(false)->maxDate(today()),
                    Select::make('locale')->label(__('fields.locale'))->options(config('salon.locales'))->default('en')->native(false),
                    Select::make('source')->label(__('fields.source'))->options(static::sourceOptions())->native(false)->default('walk_in'),
                    Select::make('tags')->label(__('workspace.clients.tags'))->relationship('tags', 'name')->multiple()->preload()->searchable()
                        ->createOptionForm([
                            TextInput::make('name')->label(__('fields.name'))->required()->maxLength(40),
                            ColorPicker::make('color')->label(__('workspace.staff.color'))->default('#C8A96A'),
                        ]),
                ]),
                Section::make(__('workspace.clients.notes_section'))->schema([
                    Textarea::make('notes')->label(__('fields.notes'))->rows(3)->helperText(__('workspace.clients.notes_help')),
                    Textarea::make('allergies')->label(__('workspace.clients.allergies'))->rows(2)
                        ->helperText(__('workspace.clients.allergies_help'))
                        ->visible(fn () => static::canSeePrivate())->dehydrated(fn () => static::canSeePrivate()),
                ]),
            ]),
            Grid::make(1)->columnSpan(['default' => 3, 'lg' => 1])->schema([
                Section::make(__('workspace.clients.preferences'))->schema([
                    Select::make('preferred_branch_id')->label(__('workspace.clients.preferred_branch'))->options(fn () => BranchStockResource::branchOptions())->native(false),
                    Select::make('preferred_staff_id')->label(__('workspace.clients.preferred_staff'))->options(fn () => Staff::query()->get()->pluck('name', 'id'))->searchable()->native(false),
                    Toggle::make('is_vip')->label(__('workspace.clients.vip')),
                    Toggle::make('is_blocked')->label(__('workspace.clients.blocked'))->helperText(__('workspace.clients.blocked_help')),
                ]),
                Section::make(__('workspace.clients.consents'))->description(__('workspace.clients.consents_help'))->schema(
                    collect(Client::CHANNELS)->map(fn ($c) => Toggle::make('consent_'.$c)->label(__('workspace.clients.channels.'.$c))
                        ->helperText(fn ($record) => ($consent = $record?->consents->firstWhere('channel', $c))
                            ? __($consent->granted ? 'workspace.clients.consent_granted_at' : 'workspace.clients.consent_revoked_at', ['date' => $consent->updated_at->translatedFormat('d M Y, H:i'), 'source' => $consent->source])
                            : __('workspace.clients.consent_never')))->all()
                ),
            ]),
        ]);
    }

    public static function consentState(Client $client): array
    {
        return collect(Client::CHANNELS)->mapWithKeys(fn ($c) => ['consent_'.$c => $client->hasConsent($c)])->all();
    }

    public static function pullConsents(array &$data): array
    {
        $consents = [];
        foreach (Client::CHANNELS as $c) {
            if (array_key_exists('consent_'.$c, $data)) {
                $consents[$c] = (bool) $data['consent_'.$c];
                unset($data['consent_'.$c]);
            }
        }

        return $consents;
    }

    public static function table(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn (Builder $query) => $query->with('tags'))
            ->defaultSort('created_at', 'desc')
            ->columns([
                TextColumn::make('name')->label(__('fields.name'))->weight('semibold')->searchable()->sortable()
                    ->description(fn (Client $r) => $r->phone)
                    ->icon(fn (Client $r) => $r->is_vip ? 'heroicon-m-star' : null)->iconColor('primary'),
                TextColumn::make('phone')->extraAttributes(['dir' => 'ltr'])->label(__('fields.phone'))->searchable()->hidden(),
                TextColumn::make('email')->label(__('fields.email'))->searchable()->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('tags.name')->label(__('workspace.clients.tags'))->badge()->color('gray')->limitList(3),
                TextColumn::make('visits_count')->label(__('workspace.clients.visits'))->alignCenter()->sortable(),
                TextColumn::make('last_visit_at')->label(__('workspace.clients.last_visit'))->since()->sortable()->placeholder(__('workspace.clients.never')),
                TextColumn::make('lifetime_value')->label(__('workspace.clients.ltv'))->alignEnd()->sortable()
                    ->formatStateUsing(fn ($state) => money((int) $state))->visible(fn () => static::canSeeRevenue()),
                TextColumn::make('wallet_balance')->label(__('workspace.clients.wallet'))->alignEnd()->formatStateUsing(fn ($state) => money((int) $state))->toggleable(),
                TextColumn::make('loyalty_points')->label(__('workspace.clients.points'))->alignCenter()->toggleable()
                    ->description(fn (Client $r) => $r->loyalty_tier ? __('workspace.clients.tier', ['tier' => ucfirst($r->loyalty_tier)]) : null),
                IconColumn::make('is_blocked')->label(__('workspace.clients.blocked'))->boolean()->trueColor('danger')->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                SelectFilter::make('tags')->label(__('workspace.clients.tags'))->relationship('tags', 'name')->multiple()->preload(),
                TernaryFilter::make('is_vip')->label(__('workspace.clients.vip')),
                SelectFilter::make('preferred_branch_id')->label(__('workspace.clients.preferred_branch'))->options(fn () => BranchStockResource::branchOptions()),
                SelectFilter::make('source')->label(__('fields.source'))->options(static::sourceOptions()),
                Filter::make('birthday_month')->label(__('workspace.clients.birthday_month'))->toggle()
                    ->query(fn (Builder $query) => $query->whereNotNull('birthday')->whereMonth('birthday', now()->month)),
                Filter::make('lapsed')->label(__('workspace.clients.lapsed'))->toggle()
                    ->query(fn (Builder $query) => $query->where('last_visit_at', '<', now()->subDays(60))),
            ])
            ->recordActions([ViewAction::make(), EditAction::make()])
            ->emptyStateIcon(Heroicon::OutlinedUsers)
            ->emptyStateHeading(__('workspace.clients.empty'))
            ->emptyStateDescription(__('workspace.clients.empty_body'));
    }

    public static function getGloballySearchableAttributes(): array
    {
        return ['name', 'phone', 'email'];
    }

    public static function getGlobalSearchResultDetails(Model $record): array
    {
        return [__('fields.phone') => $record->phone];
    }

    public static function getPages(): array
    {
        return [
            'index' => ListClients::route('/'),
            'create' => CreateClient::route('/create'),
            'view' => ViewClient::route('/{record}'),
            'edit' => EditClient::route('/{record}/edit'),
        ];
    }

    public static function tagOptions(): array
    {
        return Tag::query()->orderBy('name')->pluck('name', 'id')->all();
    }
}

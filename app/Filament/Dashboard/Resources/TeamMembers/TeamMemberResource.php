<?php

namespace App\Filament\Dashboard\Resources\TeamMembers;

use App\Domain\Branches\Models\Branch;
use App\Domain\Team\Actions\InviteTeamMember;
use App\Domain\Team\Actions\SetPosPin;
use App\Domain\Team\Actions\UpdateTeamMember;
use App\Domain\Team\Models\Staff;
use App\Filament\Dashboard\Concerns\WorkspaceResource;
use App\Filament\Dashboard\Resources\TeamMembers\Pages\ManageTeamMembers;
use App\Models\User;
use App\Support\Exceptions\DomainException;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Forms\Components\CheckboxList;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Password;

/** Team logins: dashboard / POS users of this salon with their role and branches. */
class TeamMemberResource extends Resource
{
    use WorkspaceResource;

    protected static ?string $model = User::class;

    protected static string $viewPermission = 'settings.roles';

    protected static string $managePermission = 'settings.roles';

    protected static string $navGroup = 'team';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedKey;

    protected static ?int $navigationSort = 5;

    protected static ?string $slug = 'team-logins';

    public static function getModelLabel(): string
    {
        return __('workspace.team.label');
    }

    public static function getPluralModelLabel(): string
    {
        return __('workspace.team.plural');
    }

    public static function getEloquentQuery(): Builder
    {
        // Users are not tenant-scoped models: always filter explicitly.
        return parent::getEloquentQuery()->where('tenant_id', tenant()?->id ?? 0)->where('is_platform', false);
    }

    public static function roleOptions(): array
    {
        return collect(InviteTeamMember::ROLES)->mapWithKeys(fn ($r) => [$r => __('workspace.roles.names.'.$r)])->all();
    }

    public static function memberFields(bool $invite): array
    {
        return array_values(array_filter([
            $invite ? TextInput::make('name')->label(__('fields.name'))->required()->maxLength(120) : null,
            $invite ? TextInput::make('email')->label(__('fields.email'))->email()->required()->unique('users', 'email') : null,
            Select::make('role')->label(__('workspace.team.role'))->options(static::roleOptions())->required()->native(false)->live()
                ->helperText(fn (Get $get) => $get('role') ? __('workspace.roles.help.'.$get('role')) : null),
            CheckboxList::make('branch_ids')->label(__('fields.branches'))->columns(2)->bulkToggleable()
                ->options(fn () => Branch::query()->orderBy('sort')->get()->mapWithKeys(fn ($b) => [$b->id => $b->name]))
                ->helperText(__('workspace.team.branches_help'))
                ->required(fn (Get $get) => in_array($get('role'), ['branch_manager', 'receptionist', 'stylist'], true)),
            $invite ? Select::make('staff_id')->label(__('workspace.team.link_staff'))->helperText(__('workspace.team.link_staff_help'))->native(false)
                ->options(fn () => Staff::query()->whereNull('user_id')->get()->pluck('name', 'id'))->searchable() : null,
            $invite ? null : Toggle::make('is_active')->label(__('workspace.team.active'))->helperText(__('workspace.team.active_help')),
        ]));
    }

    public static function table(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn (Builder $query) => $query->with(['roles', 'branches']))
            ->defaultSort('name')
            ->columns([
                TextColumn::make('name')->label(__('fields.name'))->weight('semibold')->searchable()->description(fn (User $r) => $r->email),
                TextColumn::make('role')->label(__('workspace.team.role'))->badge()
                    ->state(fn (User $r) => $r->isOwner() ? __('workspace.roles.names.owner') : ($r->roles->first() ? __('workspace.roles.names.'.$r->roles->first()->name) : '—'))
                    ->color(fn (User $r) => $r->isOwner() ? 'primary' : 'gray'),
                TextColumn::make('branches.name')->label(__('fields.branches'))->badge()->color('gray')
                    ->placeholder(fn (User $r) => $r->restrictedBranchIds() === null ? __('salon.ui.all_branches') : '—'),
                TextColumn::make('last_login_at')->label(__('workspace.team.last_login'))->since()->placeholder(__('workspace.team.never'))->sortable(),
                IconColumn::make('pos_pin_set')->label(__('workspace.pin.column'))
                    ->state(fn (User $r) => filled($r->pos_pin))->boolean()
                    ->trueIcon(Heroicon::OutlinedLockClosed)->falseIcon(Heroicon::OutlinedMinus)->falseColor('gray')
                    ->visible(fn () => (bool) tenant()?->hasModule('pos')),
                IconColumn::make('is_active')->label(__('workspace.team.active'))->boolean(fn ($state) => $state !== false),
            ])
            ->filters([
                SelectFilter::make('role')->label(__('workspace.team.role'))->options(static::roleOptions())
                    ->query(fn (Builder $query, array $data) => filled($data['value'] ?? null) ? $query->role($data['value']) : $query),
            ])
            ->recordActions([
                Action::make('editAccess')->label(__('workspace.team.edit_access'))->icon(Heroicon::OutlinedPencilSquare)
                    ->visible(fn (User $record) => static::canManage() && ! $record->isOwner())
                    ->fillForm(fn (User $record) => ['role' => $record->roles->first()?->name, 'branch_ids' => $record->branches->pluck('id')->all(), 'is_active' => $record->is_active !== false])
                    ->schema(static::memberFields(invite: false))
                    ->action(function (User $record, array $data) {
                        try {
                            app(UpdateTeamMember::class)->handle($record, $data['role'], $data['branch_ids'] ?? [], (bool) ($data['is_active'] ?? true));
                            Notification::make()->success()->title(__('workspace.team.updated'))->send();
                        } catch (DomainException $e) {
                            Notification::make()->danger()->title($e->getMessage())->send();
                        }
                    }),
                Action::make('setPosPin')->label(__('workspace.pin.set'))->icon(Heroicon::OutlinedLockClosed)->color('gray')
                    ->visible(fn (User $record) => static::canManage() && (bool) tenant()?->hasModule('pos')
                        && ($record->isOwner() ? $record->is(auth()->user()) : $record->can('pos.access')))
                    ->modalHeading(fn (User $record) => __('workspace.pin.set_heading', ['name' => $record->name]))
                    ->modalDescription(__('workspace.pin.set_body'))
                    ->modalWidth('md')
                    ->schema([
                        TextInput::make('pin')->label(__('workspace.pin.new'))
                            ->helperText(__('workspace.pin.help', ['min' => SetPosPin::MIN, 'max' => SetPosPin::MAX]))
                            ->password()->revealable()->autocomplete('off')
                            ->extraInputAttributes(['inputmode' => 'numeric', 'maxlength' => SetPosPin::MAX])
                            ->required(fn (Get $get) => ! $get('remove'))
                            ->regex('/^\d{'.SetPosPin::MIN.','.SetPosPin::MAX.'}$/')
                            ->validationMessages(['regex' => __('workspace.pin.format', ['min' => SetPosPin::MIN, 'max' => SetPosPin::MAX])])
                            ->disabled(fn (Get $get) => (bool) $get('remove')),
                        Toggle::make('remove')->label(__('workspace.pin.remove'))->live()
                            ->visible(fn (User $record) => filled($record->pos_pin)),
                    ])
                    ->action(function (User $record, array $data, Action $action) {
                        try {
                            $remove = (bool) ($data['remove'] ?? false);
                            app(SetPosPin::class)->handle($record, $remove ? null : (string) $data['pin']);
                            Notification::make()->success()->title(__($remove ? 'workspace.pin.removed' : 'workspace.pin.saved_for', ['name' => $record->name]))->send();
                        } catch (DomainException $e) {
                            Notification::make()->danger()->title($e->getMessage())->send();
                            $action->halt();
                        }
                    }),
                Action::make('resendInvite')->label(__('workspace.team.resend'))->icon(Heroicon::OutlinedEnvelope)->color('gray')
                    ->visible(fn (User $record) => static::canManage() && ! $record->isOwner() && ! $record->last_login_at)
                    ->requiresConfirmation()
                    ->action(function (User $record) {
                        Password::broker()->sendResetLink(['email' => $record->email]);
                        Notification::make()->success()->title(__('workspace.team.invite_sent', ['email' => $record->email]))->send();
                    }),
            ])
            ->emptyStateIcon(Heroicon::OutlinedKey)
            ->emptyStateHeading(__('workspace.team.empty'))
            ->emptyStateDescription(__('workspace.team.empty_body'));
    }

    public static function getPages(): array
    {
        return ['index' => ManageTeamMembers::route('/')];
    }
}

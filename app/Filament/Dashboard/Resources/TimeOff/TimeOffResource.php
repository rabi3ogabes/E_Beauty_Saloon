<?php

namespace App\Filament\Dashboard\Resources\TimeOff;

use App\Domain\Team\Actions\ReviewTimeOff;
use App\Domain\Team\Models\Staff;
use App\Domain\Team\Models\TimeOff;
use App\Filament\Dashboard\Concerns\WorkspaceResource;
use App\Filament\Dashboard\Resources\TimeOff\Pages\ManageTimeOff;
use App\Support\Exceptions\DomainException;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\DateTimePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

/** Time-off requests with an approval queue. Only requests of staff in the user's branches are visible. */
class TimeOffResource extends Resource
{
    use WorkspaceResource;

    protected static ?string $model = TimeOff::class;

    protected static string $viewPermission = 'staff.view';

    protected static string $managePermission = 'staff.schedules';

    protected static string $navGroup = 'team';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedSun;

    protected static ?int $navigationSort = 3;

    protected static ?string $slug = 'time-off';

    public static function getModelLabel(): string
    {
        return __('workspace.time_off.label');
    }

    public static function getPluralModelLabel(): string
    {
        return __('workspace.time_off.plural');
    }

    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()->whereHas('staff');
    }

    public static function getNavigationBadge(): ?string
    {
        return (string) static::getEloquentQuery()->where('status', 'pending')->count() ?: null;
    }

    public static function getNavigationBadgeColor(): ?string
    {
        return 'warning';
    }

    public const TYPES = ['leave', 'sick', 'block', 'training'];

    public static function fields(bool $withStaff = true): array
    {
        return array_values(array_filter([
            $withStaff ? Select::make('staff_id')->label(__('workspace.staff.label'))->required()->searchable()->native(false)
                ->options(fn () => Staff::query()->get()->pluck('name', 'id'))->columnSpanFull() : null,
            DateTimePicker::make('starts_at')->label(__('workspace.time_off.starts'))->seconds(false)->required()->native(false),
            DateTimePicker::make('ends_at')->label(__('workspace.time_off.ends'))->seconds(false)->required()->native(false)->after('starts_at'),
            Select::make('type')->label(__('fields.type'))->options(collect(self::TYPES)->mapWithKeys(fn ($t) => [$t => __('workspace.time_off.types.'.$t)]))->default('leave')->required()->native(false),
            TextInput::make('reason')->label(__('fields.reason'))->maxLength(255),
        ]));
    }

    public static function columns(bool $withStaff = true): array
    {
        return array_values(array_filter([
            $withStaff ? TextColumn::make('staff.name')->label(__('workspace.staff.label'))->weight('semibold') : null,
            TextColumn::make('starts_at')->label(__('workspace.time_off.period'))->dateTime('d M, H:i')->sortable()
                ->description(fn (TimeOff $r) => '→ '.$r->ends_at?->translatedFormat('d M, H:i')),
            TextColumn::make('type')->label(__('fields.type'))->badge()->color('gray')->formatStateUsing(fn ($state) => __('workspace.time_off.types.'.$state)),
            TextColumn::make('reason')->label(__('fields.reason'))->placeholder('—')->limit(40)->toggleable(),
            TextColumn::make('status')->label(__('fields.status'))->badge()->formatStateUsing(fn ($state) => __('workspace.time_off.status.'.$state))
                ->color(fn ($state) => match ($state) {
                    'approved' => 'success', 'rejected' => 'danger', default => 'warning'
                }),
        ]));
    }

    public static function reviewActions(): array
    {
        $review = fn (bool $approve) => function (TimeOff $record) use ($approve) {
            try {
                app(ReviewTimeOff::class)->handle($record, $approve);
                Notification::make()->success()->title(__($approve ? 'workspace.time_off.approved' : 'workspace.time_off.rejected'))->send();
            } catch (DomainException $e) {
                Notification::make()->danger()->title($e->getMessage())->send();
            }
        };

        return [
            Action::make('approve')->label(__('workspace.time_off.approve'))->icon(Heroicon::OutlinedCheck)->color('success')
                ->visible(fn (TimeOff $record) => $record->status === 'pending' && static::canManage())->action($review(true)),
            Action::make('reject')->label(__('workspace.time_off.reject'))->icon(Heroicon::OutlinedXMark)->color('danger')->requiresConfirmation()
                ->visible(fn (TimeOff $record) => $record->status === 'pending' && static::canManage())->action($review(false)),
        ];
    }

    public static function form(Schema $schema): Schema
    {
        return $schema->columns(2)->components(static::fields());
    }

    public static function table(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn (Builder $query) => $query->with('staff'))
            ->defaultSort('starts_at', 'desc')
            ->columns(static::columns())
            ->filters([
                SelectFilter::make('status')->label(__('fields.status'))->default('pending')
                    ->options(collect(['pending', 'approved', 'rejected'])->mapWithKeys(fn ($s) => [$s => __('workspace.time_off.status.'.$s)])),
                SelectFilter::make('type')->label(__('fields.type'))->options(collect(self::TYPES)->mapWithKeys(fn ($t) => [$t => __('workspace.time_off.types.'.$t)])),
            ])
            ->recordActions([...static::reviewActions(), EditAction::make(), DeleteAction::make()])
            ->emptyStateIcon(Heroicon::OutlinedSun)
            ->emptyStateHeading(__('workspace.time_off.empty'))
            ->emptyStateDescription(__('workspace.time_off.empty_body'));
    }

    public static function getPages(): array
    {
        return ['index' => ManageTimeOff::route('/')];
    }
}

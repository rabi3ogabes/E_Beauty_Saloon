<?php

namespace App\Filament\Dashboard\Pages;

use App\Domain\Tenancy\Models\ImpersonationLog;
use App\Domain\Tenancy\Models\Tenant;
use App\Models\User;
use BackedEnum;
use Filament\Pages\Page;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Concerns\InteractsWithTable;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Str;
use Spatie\Activitylog\Models\Activity;

/** Activity log of this salon (actions by its team or on its account) plus support access sessions. */
class SecurityLog extends Page implements HasTable
{
    use InteractsWithTable;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedFingerPrint;

    protected static ?int $navigationSort = 60;

    protected static ?string $slug = 'security-log';

    protected string $view = 'filament.dashboard.pages.security-log';

    public static function getNavigationGroup(): ?string
    {
        return __('salon.nav.settings');
    }

    public static function getNavigationLabel(): string
    {
        return __('workspace.security.title');
    }

    public function getTitle(): string
    {
        return __('workspace.security.title');
    }

    public function getSubheading(): ?string
    {
        return __('workspace.security.subheading');
    }

    public static function canAccess(): bool
    {
        return (bool) auth()->user()?->can('settings.manage');
    }

    public function getImpersonations()
    {
        return ImpersonationLog::query()->where('tenant_id', tenant()->id)->whereNotNull('started_at')->latest('started_at')->limit(20)->get();
    }

    /** Activities caused by this salon's users, or performed on the salon account itself. */
    public static function activityQuery(): Builder
    {
        $tenantId = tenant()->id;
        $userIds = User::query()->where('tenant_id', $tenantId)->pluck('id');

        return Activity::query()->with('causer')->where(fn ($q) => $q
            ->where(fn ($w) => $w->where('causer_type', (new User)->getMorphClass())->whereIn('causer_id', $userIds))
            ->orWhere(fn ($w) => $w->where('subject_type', (new Tenant)->getMorphClass())->where('subject_id', $tenantId)));
    }

    public function table(Table $table): Table
    {
        return $table
            ->query(static::activityQuery())
            ->defaultSort('id', 'desc')
            ->columns([
                TextColumn::make('created_at')->label(__('workspace.common.date'))->dateTime('d M Y, H:i')->sortable(),
                TextColumn::make('description')->label(__('workspace.security.event'))->badge()->color('gray')->searchable()
                    ->formatStateUsing(fn ($state) => app('translator')->has('workspace.security.events.'.$state) ? __('workspace.security.events.'.$state) : $state),
                TextColumn::make('causer.name')->label(__('workspace.common.by'))->placeholder(__('workspace.common.system')),
                TextColumn::make('subject_type')->label(__('workspace.security.subject'))->formatStateUsing(fn ($state, $record) => $state ? class_basename((string) $state).' #'.$record->subject_id : '—')->toggleable(),
                TextColumn::make('properties')->label(__('workspace.security.details'))->color('gray')->wrap()->toggleable()
                    ->formatStateUsing(fn ($state) => Str::limit(json_encode($state, JSON_UNESCAPED_UNICODE), 90)),
            ])
            ->filters([
                SelectFilter::make('description')->label(__('workspace.security.event'))
                    ->options(fn () => static::activityQuery()->distinct()->limit(100)->pluck('description', 'description')->all()),
            ])
            ->emptyStateHeading(__('workspace.security.empty'));
    }
}

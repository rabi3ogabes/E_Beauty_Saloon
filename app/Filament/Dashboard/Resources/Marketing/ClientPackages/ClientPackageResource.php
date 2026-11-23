<?php

namespace App\Filament\Dashboard\Resources\Marketing\ClientPackages;

use App\Domain\Marketing\Actions\UsePackageSession;
use App\Domain\Marketing\Models\ClientPackage;
use App\Filament\Dashboard\Resources\Marketing\ClientPackages\Pages\ManageClientPackages;
use App\Filament\Marketing\GatedResource;
use App\Filament\Marketing\MarketingAccess;
use App\Support\Exceptions\DomainException;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;

class ClientPackageResource extends Resource
{
    use GatedResource;

    protected static ?string $model = ClientPackage::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedTicket;

    protected static ?int $navigationSort = 21;

    public static function getModelLabel(): string
    {
        return __('marketing.packages.client_packages');
    }

    public static function getPluralModelLabel(): string
    {
        return __('marketing.packages.client_packages');
    }

    public static function canCreate(): bool
    {
        return false;
    }

    public static function canEdit(Model $record): bool
    {
        return false;
    }

    public static function table(Table $table): Table
    {
        $locale = app()->getLocale();

        return $table->defaultSort('id', 'desc')
            ->modifyQueryUsing(fn ($query) => $query->with(['client', 'package']))
            ->columns([
                TextColumn::make('client.name')->label(__('marketing.packages.client'))->weight('semibold')->searchable()->description(fn (ClientPackage $r) => $r->client?->phone),
                TextColumn::make('package_id')->label(__('marketing.packages.label'))->formatStateUsing(fn (ClientPackage $record) => $record->package?->getTranslation('name', $locale)),
                TextColumn::make('sessions_remaining')->label(__('marketing.packages.sessions_left'))->formatStateUsing(fn (ClientPackage $r) => $r->sessions_remaining.' / '.$r->sessions_total)->alignCenter(),
                TextColumn::make('status')->label(__('marketing.common.status'))->badge()->formatStateUsing(fn ($state) => __('marketing.packages.statuses.'.$state))
                    ->color(fn ($state) => match ($state) {
                        'active' => 'success', 'pending' => 'warning', 'expired', 'cancelled' => 'danger', default => 'gray'
                    }),
                TextColumn::make('price_paid')->label(__('marketing.packages.price'))->formatStateUsing(fn ($state) => money((int) $state))->alignEnd(),
                TextColumn::make('expires_at')->label(__('marketing.gift_cards.valid_until'))->date()->placeholder('∞')->sortable(),
            ])
            ->filters([SelectFilter::make('status')->label(__('marketing.common.status'))->options(__('marketing.packages.statuses'))])
            ->recordActions([
                Action::make('use')->label(__('marketing.packages.use_session'))->icon('heroicon-o-check-circle')
                    ->visible(fn (ClientPackage $record) => $record->isUsable() && MarketingAccess::manage())
                    ->schema([TextInput::make('sessions')->label(__('marketing.packages.sessions_count'))->numeric()->minValue(1)->default(1)->required()])
                    ->action(function (ClientPackage $record, array $data) {
                        try {
                            app(UsePackageSession::class)->handle($record, (int) $data['sessions']);
                            Notification::make()->success()->title(__('marketing.packages.session_used'))->send();
                        } catch (DomainException $e) {
                            Notification::make()->danger()->title($e->getMessage())->send();
                        }
                    }),
            ]);
    }

    public static function getPages(): array
    {
        return ['index' => ManageClientPackages::route('/')];
    }
}

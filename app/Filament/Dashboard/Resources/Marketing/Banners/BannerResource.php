<?php

namespace App\Filament\Dashboard\Resources\Marketing\Banners;

use App\Domain\Marketing\Models\Banner;
use App\Filament\Dashboard\Resources\Marketing\Banners\Pages\BannerStudio;
use App\Filament\Dashboard\Resources\Marketing\Banners\Pages\ListBanners;
use App\Filament\Marketing\GatedResource;
use App\Filament\Marketing\MarketingAccess;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Actions\DeleteAction;
use Filament\Resources\Resource;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\ImageColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

class BannerResource extends Resource
{
    use GatedResource;

    protected static ?string $model = Banner::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedPhoto;

    protected static ?int $navigationSort = 13;

    public static function getModelLabel(): string
    {
        return __('marketing.banners.label');
    }

    public static function getPluralModelLabel(): string
    {
        return __('marketing.banners.plural');
    }

    public static function getNavigationLabel(): string
    {
        return __('marketing.banners.studio');
    }

    public static function table(Table $table): Table
    {
        return $table->defaultSort('updated_at', 'desc')->columns([
            ImageColumn::make('exported_path')->label('')->disk('public')->height(64)->extraImgAttributes(['class' => 'rounded-lg']),
            TextColumn::make('name')->label(__('marketing.common.name'))->weight('semibold')->searchable(),
            TextColumn::make('format')->label(__('marketing.banners.format'))->badge()->color('gray')->formatStateUsing(fn ($state) => __('marketing.banners.formats.'.$state)),
            TextColumn::make('updated_at')->label(__('marketing.common.created'))->since(),
        ])->recordUrl(fn (Banner $record) => MarketingAccess::manage() ? static::getUrl('studio', ['record' => $record]) : null)
            ->recordActions([
                Action::make('open')->label(__('marketing.banners.open'))->icon('heroicon-o-paint-brush')->url(fn (Banner $record) => static::getUrl('studio', ['record' => $record]))->visible(fn () => MarketingAccess::manage()),
                DeleteAction::make(),
            ])
            ->emptyStateHeading(__('marketing.banners.empty'))->emptyStateDescription(__('marketing.banners.empty_help'))->emptyStateIcon('heroicon-o-photo');
    }

    public static function getPages(): array
    {
        return [
            'index' => ListBanners::route('/'),
            'create' => BannerStudio::route('/studio'),
            'studio' => BannerStudio::route('/{record}/studio'),
        ];
    }
}

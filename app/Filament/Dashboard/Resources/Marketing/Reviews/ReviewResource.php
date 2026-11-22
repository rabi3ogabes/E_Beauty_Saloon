<?php

namespace App\Filament\Dashboard\Resources\Marketing\Reviews;

use App\Domain\AIStudio\Contracts\GeneratesMarketingContent;
use App\Domain\Marketing\Actions\ReplyToReview;
use App\Domain\Marketing\Models\Review;
use App\Filament\Dashboard\Resources\Marketing\Reviews\Pages\ManageReviews;
use App\Filament\Marketing\GatedResource;
use App\Filament\Marketing\MarketingAccess;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Utilities\Set;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

class ReviewResource extends Resource
{
    use GatedResource;

    protected static ?string $model = Review::class;

    /** Reviews belong to both Marketing and Loyalty — either module unlocks them. */
    protected static array $modules = ['marketing', 'loyalty'];

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedStar;

    protected static ?int $navigationSort = 30;

    public static function getModelLabel(): string
    {
        return __('marketing.reviews.label');
    }

    public static function getPluralModelLabel(): string
    {
        return __('marketing.reviews.plural');
    }

    public static function canCreate(): bool
    {
        return false;
    }

    public static function canEdit(Model $record): bool
    {
        return false;
    }

    public static function getNavigationBadge(): ?string
    {
        $count = Review::where('status', 'private')->whereNull('replied_at')->count();

        return $count ? (string) $count : null;
    }

    public static function getNavigationBadgeColor(): ?string
    {
        return 'warning';
    }

    public static function table(Table $table): Table
    {
        $locale = app()->getLocale();

        return $table->defaultSort('created_at', 'desc')
            ->modifyQueryUsing(fn ($query) => $query->with(['client', 'staff', 'booking']))
            ->columns([
                TextColumn::make('rating')->label(__('marketing.reviews.rating'))->formatStateUsing(fn ($state) => str_repeat('★', (int) $state).str_repeat('☆', 5 - (int) $state))
                    ->color(fn ($state) => $state >= 4 ? 'success' : ($state == 3 ? 'warning' : 'danger'))->sortable(),
                TextColumn::make('comment')->label(__('marketing.reviews.comment'))->wrap()->limit(160)->placeholder('—')
                    ->description(fn (Review $r) => $r->reply ? '↳ '.Str::limit($r->reply, 120) : null),
                TextColumn::make('client.name')->label(__('marketing.reviews.client'))->searchable()->description(fn (Review $r) => $r->booking?->reference),
                TextColumn::make('staff_id')->label(__('marketing.reviews.staff'))->formatStateUsing(fn (Review $record) => $record->staff?->getTranslation('name', $locale))->placeholder('—'),
                TextColumn::make('status')->label(__('marketing.common.status'))->badge()->formatStateUsing(fn ($state) => __('marketing.reviews.statuses.'.$state))
                    ->color(fn ($state) => match ($state) {
                        'published' => 'success', 'private' => 'warning', default => 'gray'
                    }),
                TextColumn::make('created_at')->label(__('marketing.common.created'))->since()->sortable(),
            ])
            ->filters([
                SelectFilter::make('status')->label(__('marketing.common.status'))->options(__('marketing.reviews.statuses')),
                SelectFilter::make('rating')->label(__('marketing.reviews.rating'))->options([5 => '★★★★★', 4 => '★★★★', 3 => '★★★', 2 => '★★', 1 => '★']),
            ])
            ->recordActions([
                Action::make('reply')->label(__('marketing.reviews.reply'))->icon('heroicon-o-chat-bubble-left')->color('gray')
                    ->visible(fn () => MarketingAccess::manage(static::$modules))
                    ->fillForm(fn (Review $record) => ['reply' => $record->reply, 'status' => $record->status])
                    ->schema([
                        Textarea::make('reply')->label(__('marketing.reviews.reply'))->rows(5)->required()->maxLength(2000)
                            ->hintAction(Action::make('ai')->label(__('marketing.reviews.reply_draft'))->icon('heroicon-o-sparkles')
                                ->action(function (Set $set, Review $record) {
                                    $copy = app(GeneratesMarketingContent::class)->copy(
                                        'Write a warm, short reply from the salon owner to this '.$record->rating.'-star review: "'.($record->comment ?? '').'"', 'luxury'
                                    );
                                    $set('reply', $copy[($record->client?->locale === 'ar') ? 'ar' : 'en'] ?? $copy['en'] ?? '');
                                })),
                        Select::make('status')->label(__('marketing.common.status'))->options(__('marketing.reviews.statuses'))->required(),
                    ])
                    ->action(function (Review $record, array $data) {
                        app(ReplyToReview::class)->handle($record, $data['reply'], $data['status']);
                        Notification::make()->success()->title(__('marketing.reviews.replied'))->send();
                    }),
                Action::make('toggle')->label(fn (Review $record) => $record->status === 'published' ? __('marketing.reviews.make_private') : __('marketing.reviews.publish'))
                    ->icon(fn (Review $record) => $record->status === 'published' ? 'heroicon-o-eye-slash' : 'heroicon-o-eye')->color('gray')
                    ->visible(fn () => MarketingAccess::manage(static::$modules))
                    ->action(fn (Review $record) => $record->update(['status' => $record->status === 'published' ? 'private' : 'published'])),
            ])
            ->emptyStateHeading(__('marketing.reviews.empty'))->emptyStateDescription(__('marketing.reviews.empty_help'))->emptyStateIcon('heroicon-o-star');
    }

    public static function getPages(): array
    {
        return ['index' => ManageReviews::route('/')];
    }
}

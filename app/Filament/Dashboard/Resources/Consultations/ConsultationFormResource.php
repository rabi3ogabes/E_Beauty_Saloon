<?php

namespace App\Filament\Dashboard\Resources\Consultations;

use App\Domain\Insights\Actions\SendConsultationForm;
use App\Domain\Insights\Models\ConsultationForm;
use App\Filament\Dashboard\Resources\Consultations\Pages\ManageConsultationForms;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;

/** Consultation forms with e-signature, attached to clients. */
class ConsultationFormResource extends Resource
{
    protected static ?string $model = ConsultationForm::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedClipboardDocumentCheck;

    protected static ?int $navigationSort = 40;

    protected static ?string $slug = 'consultations';

    public static function canViewAny(): bool
    {
        return (bool) tenant() && (auth()->user()?->can('clients.view') ?? false);
    }

    public static function canCreate(): bool
    {
        return false;
    }

    public static function canEdit(Model $record): bool
    {
        return false;
    }

    public static function canDelete(Model $record): bool
    {
        return false;
    }

    public static function getNavigationGroup(): ?string
    {
        return __('salon.nav.clients');
    }

    public static function getModelLabel(): string
    {
        return __('insights.consultation.label');
    }

    public static function getPluralModelLabel(): string
    {
        return __('insights.consultation.plural');
    }

    public static function table(Table $table): Table
    {
        return $table->defaultSort('id', 'desc')
            ->modifyQueryUsing(fn ($query) => $query->with(['client', 'booking']))
            ->columns([
                TextColumn::make('client.name')->label(__('insights.reports.cols.clients'))->weight('semibold')->searchable()
                    ->description(fn (ConsultationForm $record) => $record->client?->phone),
                TextColumn::make('status')->label(__('salon.ui.status'))->badge()
                    ->formatStateUsing(fn (ConsultationForm $record) => __('insights.consultation.status.'.($record->isExpired() ? 'expired' : $record->status)))
                    ->color(fn (ConsultationForm $record) => $record->isSigned() ? 'success' : ($record->isExpired() ? 'gray' : 'warning')),
                TextColumn::make('booking.reference')->label(__('insights.consultation.booking'))->placeholder('—'),
                TextColumn::make('sent_at')->label(__('insights.consultation.sent'))->since()->placeholder('—'),
                TextColumn::make('signed_at')->label(__('insights.consultation.signed'))->dateTime('j M Y H:i')->placeholder('—'),
            ])
            ->filters([SelectFilter::make('status')->label(__('salon.ui.status'))->options(['sent' => __('insights.consultation.status.sent'), 'signed' => __('insights.consultation.status.signed')])])
            ->recordActions([
                Action::make('view')->label(__('salon.ui.view'))->icon(Heroicon::OutlinedEye)->modalSubmitAction(false)->modalWidth('2xl')
                    ->modalHeading(fn (ConsultationForm $record) => __('insights.consultation.label').' · '.$record->client?->name)
                    ->modalContent(fn (ConsultationForm $record) => view('filament.dashboard.insights.consultation-view', ['form' => $record])),
                Action::make('resend')->label(__('insights.consultation.resend'))->icon(Heroicon::OutlinedPaperAirplane)->color('gray')
                    ->visible(fn (ConsultationForm $record) => ! $record->isSigned() && (auth()->user()?->can('clients.manage') ?? false))
                    ->action(function (ConsultationForm $record) {
                        app(SendConsultationForm::class)->handle($record->client, $record->booking);
                        Notification::make()->success()->title(__('insights.consultation.sent_ok'))->send();
                    }),
            ])
            ->emptyStateIcon(Heroicon::OutlinedClipboardDocumentCheck)
            ->emptyStateHeading(__('insights.consultation.empty'))
            ->emptyStateDescription(__('insights.consultation.empty_body'));
    }

    public static function getPages(): array
    {
        return ['index' => ManageConsultationForms::route('/')];
    }
}

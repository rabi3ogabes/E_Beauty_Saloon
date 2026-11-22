<?php

namespace App\Filament\Admin\Resources\PlatformPayments;

use App\Filament\Admin\Resources\PlatformPayments\Pages\ManagePlatformPayments;
use App\Filament\Support\Money;
use App\Filament\Support\Translatable;
use App\Domain\Billing\Models\PlatformPayment;
use BackedEnum;
use Filament\Actions;
use Filament\Forms\Components;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns;
use Filament\Tables\Filters;
use Filament\Tables\Table;
use Filament\Notifications\Notification;

class PlatformPaymentResource extends Resource
{
    protected static ?string $model = \App\Domain\Billing\Models\PlatformPayment::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedBanknotes;

    protected static ?int $navigationSort = 2;

    public static function getNavigationGroup(): ?string
    {
        return __('salon.nav.billing');
    }

    public static function getModelLabel(): string
    {
        return __('admin.payment.label');
    }

    public static function getPluralModelLabel(): string
    {
        return __('admin.payment.plural');
    }

    public static function canViewAny(): bool
    {
        return auth()->user()?->can('platform.billing.view') ?? false;
    }

    public static function canCreate(): bool
    {
        return false && static::canViewAny();
    }

    public static function getNavigationBadge(): ?string
    {
        return (string) \App\Domain\Billing\Models\PlatformPayment::where('status', 'awaiting_approval')->count() ?: null;
    }


    public static function table(Table $table): Table
    {
        return $table->defaultSort('id', 'desc')->columns([
            Columns\TextColumn::make('tenant.name')->label(__('fields.tenant'))->searchable()->weight('semibold'),
            Columns\TextColumn::make('invoice.number')->label(__('fields.number')),
            Columns\TextColumn::make('gateway')->label(__('fields.gateway'))->badge()->color('gray'),
            Columns\TextColumn::make('status')->label(__('fields.status'))->badge()->color(fn ($state) => match ($state) { 'succeeded' => 'success', 'failed' => 'danger', 'awaiting_approval' => 'warning', default => 'gray' })
                ->formatStateUsing(fn ($state) => __('admin.payment.status.'.$state)),
            Money::column('amount')->label(__('fields.amount')),
            Columns\TextColumn::make('reference')->label(__('fields.reference'))->copyable()->placeholder('—'),
            Columns\TextColumn::make('created_at')->label(__('salon.ui.created'))->since(),
        ])->filters([
            Filters\SelectFilter::make('status')->options(collect(['pending', 'awaiting_approval', 'succeeded', 'failed'])->mapWithKeys(fn ($s) => [$s => __('admin.payment.status.'.$s)])),
        ])->recordActions([
            Actions\Action::make('proof')->label(__('admin.payment.proof'))->icon(Heroicon::OutlinedPaperClip)->color('gray')
                ->visible(fn ($record) => (bool) $record->proof_path)
                ->url(fn ($record) => \Illuminate\Support\Facades\Storage::temporaryUrl($record->proof_path, now()->addMinutes(10)), true),
            Actions\Action::make('approve')->label(__('admin.payment.approve'))->icon(Heroicon::OutlinedCheckBadge)->color('success')
                ->visible(fn ($record) => $record->status === 'awaiting_approval' && auth()->user()->can('platform.billing.manage'))
                ->requiresConfirmation()
                ->action(function ($record) {
                    $record->update(['status' => 'superseded']);
                    app(\App\Domain\Billing\Actions\MarkInvoicePaid::class)->handle($record->invoice, 'bank_transfer', $record->reference, 'approve:'.$record->id, $record->amount, auth()->id());
                    Notification::make()->success()->title(__('admin.payment.approved'))->send();
                }),
            Actions\Action::make('reject')->label(__('admin.payment.reject'))->icon(Heroicon::OutlinedXCircle)->color('danger')
                ->visible(fn ($record) => $record->status === 'awaiting_approval' && auth()->user()->can('platform.billing.manage'))
                ->schema([Components\Textarea::make('reason')->label(__('fields.reason'))->required()])
                ->action(fn ($record, array $data) => $record->update(['status' => 'failed', 'failure_reason' => $data['reason']])),
        ]);
    }

    public static function getPages(): array
    {
        return ['index' => ManagePlatformPayments::route('/')];
    }
}

<?php

namespace App\Filament\Admin\Resources\Invoices;

use App\Domain\Billing\Actions\MarkInvoicePaid;
use App\Domain\Billing\Enums\InvoiceStatus;
use App\Domain\Billing\Models\Invoice;
use App\Filament\Admin\Resources\Invoices\Pages\ListInvoices;
use App\Filament\Admin\Resources\Invoices\Pages\ViewInvoice;
use App\Filament\Support\Money;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Actions\ViewAction;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Infolists\Components\RepeatableEntry;
use Filament\Infolists\Components\TextEntry;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;

class InvoiceResource extends Resource
{
    protected static ?string $model = Invoice::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedDocumentText;

    protected static ?int $navigationSort = 1;

    protected static ?string $recordTitleAttribute = 'number';

    public static function getNavigationGroup(): ?string
    {
        return __('salon.nav.billing');
    }

    public static function getModelLabel(): string
    {
        return __('admin.invoice.label');
    }

    public static function getPluralModelLabel(): string
    {
        return __('admin.invoice.plural');
    }

    public static function canViewAny(): bool
    {
        return auth()->user()?->can('platform.billing.view') ?? false;
    }

    public static function canCreate(): bool
    {
        return false;
    }

    public static function getNavigationBadge(): ?string
    {
        return (string) Invoice::where('status', InvoiceStatus::Open)->where('due_at', '<', now())->count() ?: null;
    }

    public static function getNavigationBadgeColor(): ?string
    {
        return 'danger';
    }

    public static function table(Table $table): Table
    {
        return $table
            ->defaultSort('id', 'desc')
            ->columns([
                TextColumn::make('number')->label(__('fields.number'))->searchable()->weight('semibold')->copyable(),
                TextColumn::make('tenant.name')->label(__('fields.tenant'))->searchable()->toggleable(),
                TextColumn::make('type')->label(__('fields.type'))->badge()->color('gray')->formatStateUsing(fn ($state) => __('admin.invoice.types.'.$state)),
                TextColumn::make('status')->label(__('fields.status'))->badge(),
                Money::column('total')->label(__('fields.total')),
                TextColumn::make('issued_at')->label(__('fields.issued_at'))->date()->sortable(),
                TextColumn::make('due_at')->label(__('fields.due_at'))->date()->sortable()
                    ->color(fn (Invoice $r) => $r->status === InvoiceStatus::Open && $r->due_at?->isPast() ? 'danger' : null),
            ])
            ->filters([
                SelectFilter::make('status')->label(__('fields.status'))->options(InvoiceStatus::class),
                SelectFilter::make('type')->label(__('fields.type'))->options(collect(['subscription', 'renewal', 'proration', 'credits', 'addon'])->mapWithKeys(fn ($t) => [$t => __('admin.invoice.types.'.$t)])),
            ])
            ->recordActions([
                static::markPaidAction(),
                Action::make('pdf')->label('PDF')->icon(Heroicon::OutlinedArrowDownTray)->color('gray')
                    ->url(fn (Invoice $r) => route('admin.invoices.pdf', $r), shouldOpenInNewTab: true),
                ViewAction::make(),
            ]);
    }

    public static function markPaidAction(): Action
    {
        return Action::make('markPaid')->label(__('admin.invoice.mark_paid'))->icon(Heroicon::OutlinedCheckCircle)->color('success')
            ->visible(fn (Invoice $r) => $r->status === InvoiceStatus::Open && auth()->user()?->can('platform.billing.manage'))
            ->fillForm(fn (Invoice $r) => ['amount' => $r->balanceDue(), 'gateway' => 'bank_transfer'])
            ->schema([
                Select::make('gateway')->label(__('fields.gateway'))->required()->options([
                    'bank_transfer' => __('salon.payments.drivers.bank_transfer'), 'cash' => __('salon.payments.drivers.cash'),
                    'sadad' => 'SADAD', 'skipcash' => 'SkipCash', 'myfatoorah' => 'MyFatoorah', 'stripe' => 'Stripe',
                ]),
                Money::input('amount')->label(__('fields.amount'))->required(),
                TextInput::make('reference')->label(__('fields.reference')),
            ])
            ->action(function (Invoice $record, array $data) {
                app(MarkInvoicePaid::class)->handle($record, $data['gateway'], $data['reference'] ?? null, null, (int) $data['amount'], auth()->id());
                Notification::make()->success()->title(__('admin.invoice.paid_ok', ['number' => $record->number]))->send();
            });
    }

    public static function infolist(Schema $schema): Schema
    {
        return $schema->components([
            Section::make()->columns(4)->columnSpanFull()->schema([
                TextEntry::make('number')->label(__('fields.number'))->weight('semibold')->size('lg'),
                TextEntry::make('tenant.name')->label(__('fields.tenant')),
                TextEntry::make('status')->label(__('fields.status'))->badge(),
                TextEntry::make('total')->label(__('fields.total'))->formatStateUsing(fn ($state, Invoice $r) => money($state, $r->currency))->weight('semibold')->size('lg'),
                TextEntry::make('issued_at')->label(__('fields.issued_at'))->date(),
                TextEntry::make('due_at')->label(__('fields.due_at'))->date(),
                TextEntry::make('paid_at')->label(__('fields.paid_at'))->dateTime()->placeholder('—'),
                TextEntry::make('amount_paid')->label(__('admin.invoice.paid'))->formatStateUsing(fn ($state, Invoice $r) => money($state, $r->currency)),
            ]),
            Section::make(__('admin.invoice.items'))->columnSpanFull()->schema([
                RepeatableEntry::make('items')->hiddenLabel()->columns(4)->schema([
                    TextEntry::make('description')->hiddenLabel()->columnSpan(2),
                    TextEntry::make('quantity')->hiddenLabel()->prefix('× '),
                    TextEntry::make('amount')->hiddenLabel()->alignEnd()->formatStateUsing(fn ($state, $record) => money($state, $record->invoice->currency)),
                ]),
            ]),
        ]);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListInvoices::route('/'),
            'view' => ViewInvoice::route('/{record}'),
        ];
    }
}

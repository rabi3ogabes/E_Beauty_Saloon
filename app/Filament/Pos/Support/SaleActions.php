<?php

namespace App\Filament\Pos\Support;

use App\Domain\POS\Actions\RefundSale;
use App\Domain\POS\Actions\VerifyPosPin;
use App\Domain\POS\Enums\SaleStatus;
use App\Domain\POS\Models\Sale;
use App\Domain\POS\Models\Shift;
use App\Support\Exceptions\DomainException;
use Filament\Actions\Action;
use Filament\Forms\Components\Radio;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Schemas\Components\Section;
use Filament\Support\Icons\Heroicon;

/** Refund / void / receipt actions shared by the POS sales list and the dashboard Sales resource. */
class SaleActions
{
    public static function receipt(): Action
    {
        return Action::make('receipt')->label(__('pos.sales.receipt'))->icon(Heroicon::OutlinedPrinter)->color('gray')
            ->visible(fn (Sale $record) => $record->isCompleted())
            ->url(fn (Sale $record) => route('pos.receipt.print', ['sale' => $record->id, 'preview' => 1]), shouldOpenInNewTab: true);
    }

    public static function refund(): Action
    {
        return Action::make('refund')->label(__('pos.refund.action'))->icon(Heroicon::OutlinedArrowUturnLeft)->color('warning')
            ->visible(fn (Sale $record) => in_array($record->status, [SaleStatus::Completed, SaleStatus::PartiallyRefunded], true)
                && (auth()->user()?->can('pos.access') || auth()->user()?->can('pos.refund')))
            ->modalHeading(fn (Sale $record) => __('pos.refund.title', ['number' => $record->number]))
            ->modalWidth('lg')
            ->schema(fn (Sale $record) => [
                Section::make(__('pos.refund.items'))->schema(
                    $record->loadMissing('items')->items->filter(fn ($item) => $item->refundableQty() > 0)->map(fn ($item) => TextInput::make('qty.'.$item->id)
                        ->label($item->name.' · '.money($item->amountFor(1), $record->currency))
                        ->helperText(__('pos.refund.qty', ['max' => $item->refundableQty()]))
                        ->numeric()->minValue(0)->maxValue($item->refundableQty())->default($item->refundableQty())->required())->values()->all()
                ),
                Radio::make('method')->label(__('pos.refund.method'))->default('original')->required()->options(array_filter([
                    'original' => __('pos.refund.original'),
                    'store_credit' => $record->client_id ? __('pos.refund.store_credit') : null,
                ])),
                Textarea::make('reason')->label(__('pos.refund.reason'))->required()->rows(2),
                TextInput::make('manager_pin')->label(__('pos.refund.manager_pin'))->password()->revealable(false)
                    ->visible(fn () => ! auth()->user()?->can('pos.refund'))->required(fn () => ! auth()->user()?->can('pos.refund')),
            ])
            ->action(function (Sale $record, array $data, Action $action) {
                try {
                    $user = auth()->user();
                    $authorizer = $user->can('pos.refund') ? $user : app(VerifyPosPin::class)->handle((string) ($data['manager_pin'] ?? ''), 'pos.refund');
                    $refund = app(RefundSale::class)->handle(
                        $record, collect($data['qty'] ?? [])->map(fn ($q) => (int) $q)->all(), ($data['method'] ?? 'original') === 'store_credit',
                        $data['reason'] ?? null, $user, $authorizer, 'refund', static::currentShift($record),
                    );
                    Notification::make()->success()->title(__('pos.refund.done', ['amount' => money((int) $refund->amount, $record->currency)]))->send();
                } catch (DomainException $e) {
                    Notification::make()->danger()->title($e->getMessage())->send();
                    $action->halt();
                }
            });
    }

    /** Void = full reversal; always needs a manager PIN (managers enter their own). */
    public static function void(): Action
    {
        return Action::make('void')->label(__('pos.refund.void'))->icon(Heroicon::OutlinedNoSymbol)->color('danger')
            ->visible(fn (Sale $record) => $record->status === SaleStatus::Completed && (int) $record->refunded_total === 0)
            ->modalHeading(fn (Sale $record) => __('pos.refund.void_title', ['number' => $record->number]))
            ->modalWidth('md')
            ->schema([
                Textarea::make('reason')->label(__('pos.refund.reason'))->required()->rows(2),
                TextInput::make('manager_pin')->label(__('pos.refund.manager_pin'))->password()->required(),
            ])
            ->action(function (Sale $record, array $data, Action $action) {
                try {
                    $manager = app(VerifyPosPin::class)->handle((string) $data['manager_pin'], 'pos.void');
                    app(RefundSale::class)->handle($record, null, false, $data['reason'], auth()->user(), $manager, 'void', static::currentShift($record));
                    Notification::make()->success()->title(__('pos.refund.voided'))->send();
                } catch (DomainException $e) {
                    Notification::make()->danger()->title($e->getMessage())->send();
                    $action->halt();
                }
            });
    }

    /** Cash leaves the drawer of the register's open shift (falls back to the sale's own shift if still open). */
    protected static function currentShift(Sale $sale): ?Shift
    {
        $registerId = (int) session('pos.register_id') ?: $sale->pos_register_id;

        return $registerId ? Shift::where('pos_register_id', $registerId)->where('status', 'open')->first() : null;
    }
}

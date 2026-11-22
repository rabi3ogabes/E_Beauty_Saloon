<?php

namespace App\Filament\Support;

use App\Support\Money\Money as MoneyValue;
use Filament\Forms\Components\TextInput;
use Filament\Tables\Columns\TextColumn;

/**
 * Money fields for Filament: users type decimals ("149.50"), we store integer minor units (14950).
 */
class Money
{
    public static function input(string $name, ?string $currency = null): TextInput
    {
        $currency ??= tenant()?->currency ?? config('salon.defaults.currency');

        return TextInput::make($name)
            ->numeric()
            ->minValue(0)
            ->step('0.01')
            ->inputMode('decimal')
            ->prefix($currency)
            ->formatStateUsing(fn ($state) => $state === null || $state === '' ? null : MoneyValue::of((int) $state, $currency)->toDecimal())
            ->dehydrateStateUsing(fn ($state) => $state === null || $state === '' ? null : MoneyValue::fromDecimal($state, $currency)->amount);
    }

    public static function column(string $name, ?string $currency = null): TextColumn
    {
        return TextColumn::make($name)
            ->formatStateUsing(fn ($state, $record) => money((int) $state, $currency ?? $record->currency ?? null))
            ->alignEnd()
            ->sortable();
    }
}

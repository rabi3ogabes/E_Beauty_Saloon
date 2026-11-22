<?php

namespace App\Filament\Support;

use Filament\Forms\Components\Field;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Components\Grid;

/**
 * Side-by-side English / Arabic inputs for a spatie-translatable JSON attribute.
 * Saves as ['en' => ..., 'ar' => ...].
 */
class Translatable
{
    public static function make(string $name, ?string $label = null, bool $required = true, bool $textarea = false, int $rows = 3): Grid
    {
        $label ??= __('fields.'.$name);

        $field = function (string $locale) use ($name, $label, $required, $textarea, $rows): Field {
            $component = $textarea
                ? Textarea::make("{$name}.{$locale}")->rows($rows)->autosize()
                : TextInput::make("{$name}.{$locale}")->maxLength(255);

            return $component
                ->label($label.' · '.($locale === 'ar' ? 'العربية' : 'English'))
                ->extraInputAttributes($locale === 'ar' ? ['dir' => 'rtl', 'lang' => 'ar'] : ['dir' => 'ltr', 'lang' => 'en'])
                ->required($required && $locale === 'en')
                ->afterStateHydrated(function ($component, $record) use ($name, $locale) {
                    if ($record && method_exists($record, 'getTranslation')) {
                        $component->state($record->getTranslation($name, $locale, false) ?: null);
                    }
                });
        };

        return Grid::make(['default' => 1, 'md' => 2])->schema([$field('en'), $field('ar')])->columnSpanFull();
    }
}

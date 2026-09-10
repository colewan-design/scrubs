<?php

namespace App\Filament\Support;

use Filament\Forms\Components\TextInput;

/**
 * A dollars-and-cents field over an integer-cents column.
 *
 * Money is stored in cents everywhere in this application and never as a float.
 * Asking an administrator to type 6500 to mean $65.00 is how a price ends up
 * wrong by two orders of magnitude, so the conversion happens here — at the
 * form boundary — and nowhere else.
 */
class MoneyInput
{
    public static function make(string $name, ?string $label = null): TextInput
    {
        return TextInput::make($name)
            ->label($label)
            ->numeric()
            ->prefix('CA$')
            ->step('0.01')
            ->minValue(0)
            ->formatStateUsing(
                fn (?int $state): ?string => $state === null
                    ? null
                    : number_format($state / 100, 2, '.', '')
            )
            ->dehydrateStateUsing(
                fn (mixed $state): ?int => ($state === null || $state === '')
                    ? null
                    : (int) round(((float) $state) * 100)
            );
    }
}

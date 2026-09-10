<?php

namespace App\Filament\Resources\PricingTiers\Schemas;

use App\Models\PricingTier;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Illuminate\Support\Str;

/**
 * §3 and §9: "Tier thresholds and pricing must be editable by the administrator
 * without code changes." This is that screen.
 *
 * Money is entered in dollars and stored as integer cents, so the admin never
 * has to think in cents and the database never holds a float.
 */
class PricingTierForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema->components([
            Section::make('Tier')
                ->columns(2)
                ->schema([
                    TextInput::make('name')
                        ->required()
                        ->maxLength(120)
                        ->live(onBlur: true)
                        ->afterStateUpdated(fn ($state, callable $set) => $set('slug', Str::slug((string) $state))),

                    TextInput::make('slug')
                        ->required()
                        ->maxLength(120)
                        ->unique(ignoreRecord: true)
                        ->helperText('Used in links and reports. Avoid changing it once live.'),

                    Textarea::make('description')
                        ->rows(2)
                        ->columnSpanFull()
                        ->helperText('Optional. Shown on the Wholesale page.'),
                ]),

            Section::make('How a customer qualifies')
                ->description(
                    'Set a dollar threshold, a unit threshold, or both. Leave a field blank '
                    .'to ignore it. Qualification is always measured on the retail subtotal '
                    .'before any discount, so a discount can never push a customer back out '
                    .'of the tier it just put them in.'
                )
                ->columns(3)
                ->schema([
                    TextInput::make('min_subtotal_cents')
                        ->label('Minimum order value')
                        ->numeric()
                        ->prefix('CAD $')
                        ->minValue(0)
                        ->formatStateUsing(fn (?int $state) => $state === null ? null : $state / 100)
                        ->dehydrateStateUsing(fn ($state) => $state === null || $state === ''
                            ? null
                            : (int) round((float) $state * 100))
                        ->helperText('e.g. 200'),

                    TextInput::make('min_qty')
                        ->label('Minimum units')
                        ->numeric()
                        ->minValue(1)
                        ->helperText('e.g. 10'),

                    Select::make('qualify_mode')
                        ->label('Rule')
                        ->options([
                            'any' => 'Either threshold qualifies',
                            'all' => 'Both must be met',
                        ])
                        ->default('any')
                        ->required(),
                ]),

            Section::make('Pricing')
                ->columns(2)
                ->schema([
                    Select::make('discount_type')
                        ->label('Discount type')
                        ->options([
                            PricingTier::DISCOUNT_ABSOLUTE => 'Fixed price per unit',
                            PricingTier::DISCOUNT_PERCENT => 'Percentage off',
                            PricingTier::DISCOUNT_FIXED_OFF => 'Fixed amount off per unit',
                        ])
                        ->default(PricingTier::DISCOUNT_ABSOLUTE)
                        ->live()
                        ->required(),

                    // One field, three meanings — labelled and scaled to match the
                    // selected type so the admin is never converting units by hand.
                    TextInput::make('discount_value')
                        ->label(fn ($get) => match ($get('discount_type')) {
                            PricingTier::DISCOUNT_PERCENT => 'Percentage off',
                            PricingTier::DISCOUNT_FIXED_OFF => 'Amount off each unit',
                            default => 'Price per unit',
                        })
                        ->prefix(fn ($get) => $get('discount_type') === PricingTier::DISCOUNT_PERCENT ? null : 'CAD $')
                        ->suffix(fn ($get) => $get('discount_type') === PricingTier::DISCOUNT_PERCENT ? '%' : null)
                        ->numeric()
                        ->required()
                        ->minValue(0)
                        ->formatStateUsing(fn ($state, $get) => $state === null
                            ? null
                            : (int) $state / 100)
                        ->dehydrateStateUsing(fn ($state) => (int) round((float) $state * 100))
                        ->helperText(fn ($get) => $get('discount_type') === PricingTier::DISCOUNT_PERCENT
                            ? 'e.g. 15 for 15% off'
                            : 'e.g. 45 for $45.00'),
                ]),

            Section::make('Status')
                ->columns(2)
                ->schema([
                    TextInput::make('priority')
                        ->numeric()
                        ->default(1)
                        ->required()
                        ->minValue(0)
                        ->helperText('When a cart qualifies for several tiers, the highest priority wins.'),

                    Toggle::make('is_active')
                        ->label('Active')
                        ->default(true)
                        ->helperText('Inactive tiers are ignored by the cart.'),
                ]),
        ]);
    }
}

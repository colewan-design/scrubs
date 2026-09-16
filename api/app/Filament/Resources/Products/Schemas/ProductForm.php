<?php

namespace App\Filament\Resources\Products\Schemas;

use App\Filament\Support\MoneyInput;
use App\Models\Color;
use App\Models\Size;
use Filament\Forms\Components\DateTimePicker;
use Filament\Forms\Components\Placeholder;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;
use Illuminate\Support\Str;

class ProductForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make('Product')
                    ->columns(2)
                    ->schema([
                        TextInput::make('name')
                            ->required()
                            ->maxLength(255)
                            ->live(onBlur: true)
                            // Only fill the slug while creating: changing it on a
                            // published product breaks its URL and any link to it.
                            ->afterStateUpdated(function (string $operation, $state, callable $set): void {
                                if ($operation === 'create') {
                                    $set('slug', Str::slug($state));
                                }
                            }),

                        TextInput::make('slug')
                            ->required()
                            ->maxLength(255)
                            ->unique(ignoreRecord: true)
                            ->helperText('Appears in the product URL.'),

                        Select::make('category_id')
                            ->label('Category')
                            ->relationship('category', 'name')
                            ->searchable()
                            ->preload()
                            ->required(),

                        TextInput::make('base_sku')
                            ->label('Base SKU')
                            ->required()
                            ->maxLength(255)
                            ->unique(ignoreRecord: true)
                            ->helperText('Variant SKUs are built from this.'),

                        Select::make('product_type')
                            ->label('Type')
                            // Matches the values documented on the products
                            // migration: set | top | bottom.
                            ->options([
                                'set' => 'Set (top + bottom)',
                                'top' => 'Top only',
                                'bottom' => 'Bottom only',
                            ])
                            ->default('set')
                            ->required(),

                        Toggle::make('has_dual_sizing')
                            ->label('Sized top and bottom separately')
                            ->live()
                            ->helperText('Adds a second size to every variant of this product.'),
                    ]),

                // Variants are defined here rather than in a separate panel: the
                // catalogue is a plain colour x size matrix, so picking the two
                // lists is the whole job. The Inventory panel below still owns
                // stock, because those changes belong in the ledger.
                Section::make('Variants')
                    ->description('Pick the colours and sizes this product comes in. Every combination becomes a variant, with SKUs built from the Base SKU.')
                    ->columns(2)
                    ->schema([
                        Select::make('variant_color_ids')
                            ->label('Colours')
                            ->multiple()
                            ->searchable()
                            ->preload()
                            ->live()
                            ->options(fn (): array => Color::query()
                                ->where('is_active', true)
                                ->orderBy('position')
                                ->pluck('name', 'id')
                                ->all())
                            // Typing a colour that does not exist yet creates it,
                            // so an admin is never blocked by the Colours screen.
                            ->createOptionForm([
                                TextInput::make('name')->required()->maxLength(255),
                                TextInput::make('hex')
                                    ->label('Swatch colour')
                                    ->required()
                                    ->maxLength(7)
                                    ->regex('/^#[0-9A-Fa-f]{6}$/')
                                    ->helperText('Hex, e.g. #2C3A52. Shown as the swatch on the product page.'),
                            ])
                            ->createOptionUsing(fn (array $data): int => Color::create([
                                'name' => $data['name'],
                                'slug' => Str::slug($data['name']),
                                'hex' => $data['hex'],
                                'position' => (int) Color::max('position') + 1,
                                'is_active' => true,
                            ])->getKey()),

                        Select::make('variant_size_ids')
                            ->label(fn (Get $get): string => $get('has_dual_sizing') ? 'Top sizes' : 'Sizes')
                            ->multiple()
                            ->preload()
                            ->live()
                            ->options(fn (): array => Size::query()
                                ->where('is_active', true)
                                ->orderBy('position')
                                ->pluck('name', 'id')
                                ->all())
                            ->createOptionForm([
                                TextInput::make('name')->required()->maxLength(255),
                            ])
                            ->createOptionUsing(fn (array $data): int => Size::create([
                                'name' => $data['name'],
                                'slug' => Str::slug($data['name']),
                                'position' => (int) Size::max('position') + 1,
                                'is_active' => true,
                            ])->getKey()),

                        Select::make('variant_secondary_size_ids')
                            ->label('Bottom sizes')
                            ->multiple()
                            ->preload()
                            ->live()
                            ->visible(fn (Get $get): bool => (bool) $get('has_dual_sizing'))
                            ->options(fn (): array => Size::query()
                                ->where('is_active', true)
                                ->orderBy('position')
                                ->pluck('name', 'id')
                                ->all())
                            ->helperText('Leave blank to pair every top size with the same bottom size.'),

                        Placeholder::make('variant_preview')
                            ->label('Result')
                            ->content(function (Get $get): string {
                                $colors = count($get('variant_color_ids') ?? []);
                                $sizes = count($get('variant_size_ids') ?? []);

                                if ($colors === 0 || $sizes === 0) {
                                    return 'Pick at least one colour and one size.';
                                }

                                $total = $colors * $sizes;

                                if ($get('has_dual_sizing')) {
                                    $bottoms = count($get('variant_secondary_size_ids') ?? []) ?: $sizes;
                                    $total *= $bottoms;
                                }

                                return "{$colors} colours x {$sizes} sizes = {$total} variants. "
                                    .'Removing an option hides its variants but keeps their stock and order history.';
                            }),
                    ]),

                Section::make('Pricing')
                    ->columns(2)
                    ->schema([
                        MoneyInput::make('retail_price_cents', 'Retail price')
                            ->required(),

                        MoneyInput::make('wholesale_base_price_cents', 'Wholesale reference')
                            ->helperText('A reference figure only. What a customer is charged comes from the wholesale tiers.'),

                        TextInput::make('low_stock_threshold')
                            ->label('Low stock at')
                            ->numeric()
                            ->minValue(0)
                            ->default(5)
                            ->required()
                            ->helperText('Variants at or below this are flagged in the inventory list.'),
                    ]),

                Section::make('Description')
                    ->schema([
                        Textarea::make('short_description')
                            ->label('Short description')
                            ->rows(2)
                            ->maxLength(500)
                            ->helperText('One line, shown on the product card.')
                            ->columnSpanFull(),

                        // The three accordion panels on the product page (§2).
                        Textarea::make('description')->rows(5)->columnSpanFull(),
                        Textarea::make('materials')->rows(3)->columnSpanFull(),
                        Textarea::make('dimensions_fit')
                            ->label('Dimensions & fit')
                            ->rows(3)
                            ->columnSpanFull(),

                        Select::make('size_chart_id')
                            ->label('Size chart')
                            ->relationship('sizeChart', 'name')
                            ->searchable()
                            ->preload()
                            ->placeholder('Use the category size chart'),
                    ]),

                Section::make('Publishing')
                    ->columns(2)
                    ->schema([
                        Toggle::make('is_active')
                            ->label('Active')
                            ->default(true)
                            ->helperText('Inactive products are hidden from the storefront entirely.'),

                        Toggle::make('is_featured')
                            ->label('Featured')
                            ->helperText('Surfaces the product on the homepage.'),

                        DateTimePicker::make('published_at')
                            ->label('Published at')
                            ->helperText('Leave blank to keep the product unpublished.'),

                        TextInput::make('meta_title')->label('SEO title')->maxLength(255),
                        TextInput::make('meta_description')
                            ->label('SEO description')
                            ->maxLength(500)
                            ->columnSpanFull(),
                    ]),
            ]);
    }
}

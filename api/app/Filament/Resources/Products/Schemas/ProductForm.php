<?php

namespace App\Filament\Resources\Products\Schemas;

use App\Filament\Support\MoneyInput;
use App\Models\Product;
use Filament\Forms\Components\DateTimePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Components\Section;
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
                            ->helperText('Adds a second size to every variant of this product.'),
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

<?php

namespace App\Filament\Resources\Products\RelationManagers;

use App\Models\ProductImage;
use Filament\Actions\Action;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\CreateAction;
use Filament\Actions\DeleteAction;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\ImageColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

/**
 * Product photography (§2, §9).
 *
 * Images may carry a colour, which is what lets the gallery switch when a
 * shopper picks a swatch on the product page. An image with no colour is a
 * general shot and shows for every selection.
 *
 * Alt text is authored here and never auto-generated from a filename (WCAG
 * 1.1.1) — the field is prompted but left to a human.
 */
class ImagesRelationManager extends RelationManager
{
    protected static string $relationship = 'images';

    protected static ?string $title = 'Photos';

    public static function getBadge($ownerRecord, string $pageClass): ?string
    {
        return (string) $ownerRecord->images()->count();
    }

    public function form(Schema $schema): Schema
    {
        return $schema
            ->components([
                FileUpload::make('path')
                    ->label('Image')
                    ->image()
                    ->disk('public')
                    ->directory('products')
                    ->visibility('public')
                    ->imageEditor()
                    ->maxSize(8192)
                    ->acceptedFileTypes(['image/jpeg', 'image/png', 'image/webp'])
                    ->required()
                    ->helperText('JPEG, PNG or WebP, up to 8 MB. Portrait crops suit the product grid best.')
                    ->columnSpanFull(),

                Select::make('color_id')
                    ->label('Colour')
                    ->relationship('color', 'name')
                    ->searchable()
                    ->preload()
                    ->placeholder('Shows for every colour')
                    ->helperText('Set this and the photo appears when the shopper picks that swatch.'),

                TextInput::make('alt_text')
                    ->label('Alt text')
                    ->maxLength(255)
                    ->helperText('Describe the photo for screen readers, e.g. "Navy scrub top, front view".')
                    ->columnSpanFull(),

                TextInput::make('position')
                    ->label('Sort order')
                    ->numeric()
                    ->default(0)
                    ->minValue(0),
            ]);
    }

    public function table(Table $table): Table
    {
        return $table
            ->recordTitleAttribute('alt_text')
            ->defaultSort('position')
            ->reorderable('position')
            ->columns([
                ImageColumn::make('path')
                    ->label('Photo')
                    ->disk('public')
                    ->height(72)
                    // Seeded placeholders are served by the storefront, not by
                    // this disk, so the column has to resolve both shapes.
                    ->state(fn (ProductImage $record): string => $record->url),

                TextColumn::make('color.name')
                    ->label('Colour')
                    ->placeholder('All colours')
                    ->badge(),

                TextColumn::make('alt_text')
                    ->label('Alt text')
                    ->placeholder('Not set')
                    ->wrap()
                    ->limit(60),

                TextColumn::make('position')->label('Order')->numeric()->sortable(),

                TextColumn::make('is_primary')
                    ->label('Primary')
                    ->badge()
                    ->formatStateUsing(fn (bool $state): string => $state ? 'Primary' : '')
                    ->color('success'),
            ])
            ->headerActions([
                CreateAction::make()->label('Add photo'),
            ])
            ->recordActions([
                self::makePrimaryAction(),
                EditAction::make(),
                DeleteAction::make(),
            ])
            ->toolbarActions([
                BulkActionGroup::make([
                    DeleteBulkAction::make(),
                ]),
            ])
            ->emptyStateHeading('No photos yet')
            ->emptyStateDescription('The product grid shows a placeholder until at least one photo is added.');
    }

    /**
     * Exactly one primary per product — it is what the grid and the cart line
     * render, so two would make the choice arbitrary.
     */
    protected static function makePrimaryAction(): Action
    {
        return Action::make('makePrimary')
            ->label('Make primary')
            ->icon('heroicon-o-star')
            ->hidden(fn (ProductImage $record): bool => (bool) $record->is_primary)
            ->action(function (ProductImage $record): void {
                ProductImage::query()
                    ->where('product_id', $record->product_id)
                    ->update(['is_primary' => false]);

                $record->update(['is_primary' => true]);
            });
    }
}

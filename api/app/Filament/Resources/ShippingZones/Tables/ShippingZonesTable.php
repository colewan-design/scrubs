<?php

namespace App\Filament\Resources\ShippingZones\Tables;

use App\Models\ShippingZone;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Table;

class ShippingZonesTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->defaultSort('position')
            ->reorderable('position')
            ->columns([
                TextColumn::make('name')->searchable()->sortable(),

                TextColumn::make('provinces')
                    ->label('Covers')
                    ->badge()
                    ->separator(',')
                    // An empty list is the catch-all, which reads as a mistake
                    // unless it is spelled out.
                    ->placeholder('Anywhere else'),

                TextColumn::make('rates_count')
                    ->label('Rates')
                    ->counts('rates')
                    ->badge()
                    ->color(fn (int $state): string => $state === 0 ? 'danger' : 'gray')
                    ->tooltip(fn (int $state): ?string => $state === 0
                        ? 'A zone with no rates offers the customer nothing at checkout.'
                        : null),

                TextColumn::make('position')->label('Priority')->numeric()->sortable(),

                IconColumn::make('is_active')->label('Active')->boolean(),
            ])
            ->filters([
                TernaryFilter::make('is_active')->label('Active'),
            ])
            ->recordActions([
                EditAction::make(),
            ])
            ->toolbarActions([
                BulkActionGroup::make([
                    DeleteBulkAction::make(),
                ]),
            ]);
    }
}

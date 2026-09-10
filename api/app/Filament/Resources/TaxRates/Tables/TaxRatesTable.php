<?php

namespace App\Filament\Resources\TaxRates\Tables;

use App\Filament\Resources\TaxRates\Schemas\TaxRateForm;
use App\Models\TaxRate;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Table;

class TaxRatesTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->defaultSort('province')
            ->columns([
                TextColumn::make('province')
                    ->badge()
                    ->sortable()
                    ->searchable()
                    ->description(fn (TaxRate $record): string => TaxRateForm::PROVINCES[$record->province] ?? ''),

                TextColumn::make('tax_type')->label('Tax')->badge()->sortable(),

                TextColumn::make('rate_bps')
                    ->label('Rate')
                    ->sortable()
                    ->formatStateUsing(fn (int $state): string => rtrim(rtrim(number_format($state / 100, 3, '.', ''), '0'), '.').'%'),

                TextColumn::make('applies_to')->label('Applies to')->badge()->color('gray'),

                TextColumn::make('effective_from')->date()->placeholder('Always')->toggleable(),
                TextColumn::make('effective_to')->date()->placeholder('—')->toggleable(),

                IconColumn::make('is_active')->label('Active')->boolean(),
            ])
            ->filters([
                SelectFilter::make('province')->options(TaxRateForm::PROVINCES),
                SelectFilter::make('tax_type')->label('Tax')->options([
                    'GST' => 'GST', 'HST' => 'HST', 'PST' => 'PST', 'QST' => 'QST',
                ]),
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

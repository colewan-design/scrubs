<?php

namespace App\Filament\Resources\SizeCharts\Schemas;

use Filament\Forms\Components\RichEditor;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Schema;

/**
 * Size charts (§2).
 *
 * A chart is attached to a product, or to a category as its default, and opens
 * from the product page. The body is authored as a table because that is what a
 * measurements chart is — the editor's table controls are the point of using a
 * rich editor here rather than a textarea.
 */
class SizeChartForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                TextInput::make('name')
                    ->required()
                    ->maxLength(255)
                    ->helperText('e.g. "Women\'s tops" — shown as the heading when the chart opens.')
                    ->columnSpanFull(),

                RichEditor::make('body')
                    ->label('Chart')
                    ->toolbarButtons([
                        'bold', 'italic', 'bulletList', 'orderedList', 'table', 'undo', 'redo',
                    ])
                    ->helperText('Insert a table and give it a header row — measurements in inches or centimetres, as the client supplies them.')
                    ->columnSpanFull(),
            ]);
    }
}

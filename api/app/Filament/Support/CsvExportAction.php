<?php

namespace App\Filament\Support;

use Filament\Actions\Action;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Export the table the administrator is currently looking at (§9).
 *
 * Filament's own exporter is queue-backed and needs its own table and worker.
 * At this catalogue's size that is infrastructure for nothing: streaming the
 * rows straight to the browser gives the same file with no moving parts, and
 * respects whatever filters and search are active — exporting the full table
 * when someone has filtered to last month's orders is not what they asked for.
 *
 * Rows are chunked so a large export cannot exhaust memory.
 */
class CsvExportAction
{
    /**
     * @param  array<string, callable(mixed): mixed>  $columns  header => value resolver
     */
    public static function make(string $filenamePrefix, array $columns): Action
    {
        return Action::make('exportCsv')
            ->label('Export CSV')
            ->icon('heroicon-o-arrow-down-tray')
            ->color('gray')
            ->action(function ($livewire) use ($filenamePrefix, $columns): StreamedResponse {
                $query = method_exists($livewire, 'getFilteredSortedTableQuery')
                    ? $livewire->getFilteredSortedTableQuery()
                    : $livewire->getFilteredTableQuery();

                $filename = $filenamePrefix.'-'.now()->format('Y-m-d-His').'.csv';

                return response()->streamDownload(function () use ($query, $columns): void {
                    $handle = fopen('php://output', 'w');

                    // Excel reads a UTF-8 CSV as Windows-1252 without this, which
                    // turns every accented Quebec address into mojibake.
                    fwrite($handle, "\xEF\xBB\xBF");

                    fputcsv($handle, array_keys($columns));

                    $query->chunk(200, function ($rows) use ($handle, $columns): void {
                        foreach ($rows as $row) {
                            fputcsv($handle, array_map(
                                fn (callable $resolve): mixed => $resolve($row),
                                array_values($columns),
                            ));
                        }
                    });

                    fclose($handle);
                }, $filename, [
                    'Content-Type' => 'text/csv; charset=UTF-8',
                ]);
            });
    }

    /** Cents to a plain decimal — no currency symbol, so a spreadsheet can sum it. */
    public static function money(?int $cents): string
    {
        return number_format(($cents ?? 0) / 100, 2, '.', '');
    }
}

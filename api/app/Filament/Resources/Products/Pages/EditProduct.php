<?php

namespace App\Filament\Resources\Products\Pages;

use App\Filament\Resources\Products\ProductResource;
use App\Models\Product;
use App\Support\VariantMatrix;
use Filament\Actions\DeleteAction;
use Filament\Actions\ForceDeleteAction;
use Filament\Actions\RestoreAction;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\EditRecord;

class EditProduct extends EditRecord
{
    protected static string $resource = ProductResource::class;

    /**
     * The colour/size picks from the Variants section. They are form state, not
     * columns, so they are lifted out before the product is saved and applied
     * afterwards — the product needs an ID before variants can hang off it.
     *
     * @var array<string, array<int>>
     */
    protected array $variantSelection = [];

    protected function getHeaderActions(): array
    {
        return [
            DeleteAction::make(),
            ForceDeleteAction::make(),
            RestoreAction::make(),
        ];
    }

    protected function mutateFormDataBeforeFill(array $data): array
    {
        /** @var Product $product */
        $product = $this->getRecord();

        return [...$data, ...VariantMatrix::currentSelection($product)];
    }

    protected function mutateFormDataBeforeSave(array $data): array
    {
        foreach (['variant_color_ids', 'variant_size_ids', 'variant_secondary_size_ids'] as $key) {
            $this->variantSelection[$key] = $data[$key] ?? [];
            unset($data[$key]);
        }

        return $data;
    }

    protected function afterSave(): void
    {
        /** @var Product $product */
        $product = $this->getRecord();

        $result = VariantMatrix::sync(
            $product,
            $this->variantSelection['variant_color_ids'] ?? [],
            $this->variantSelection['variant_size_ids'] ?? [],
            $this->variantSelection['variant_secondary_size_ids'] ?? [],
        );

        if (array_sum($result) === 0) {
            return;
        }

        // Say plainly what happened to the variants, because the counts are the
        // only visible sign that picking two lists rewrote 35 rows.
        $parts = [];

        if ($result['created']) {
            $parts[] = "{$result['created']} added";
        }

        if ($result['reactivated']) {
            $parts[] = "{$result['reactivated']} restored";
        }

        if ($result['deactivated']) {
            $parts[] = "{$result['deactivated']} hidden (stock and order history kept)";
        }

        Notification::make()
            ->title('Variants updated')
            ->body(implode(', ', $parts).'.')
            ->success()
            ->send();
    }
}

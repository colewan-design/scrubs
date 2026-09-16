<?php

namespace App\Filament\Resources\Products\Pages;

use App\Filament\Resources\Products\ProductResource;
use App\Models\Product;
use App\Support\VariantMatrix;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\CreateRecord;

class CreateProduct extends CreateRecord
{
    protected static string $resource = ProductResource::class;

    /**
     * Colour/size picks from the Variants section — form state rather than
     * columns, so they are held here until the product has an ID to attach to.
     *
     * @var array<string, array<int>>
     */
    protected array $variantSelection = [];

    protected function mutateFormDataBeforeCreate(array $data): array
    {
        foreach (['variant_color_ids', 'variant_size_ids', 'variant_secondary_size_ids'] as $key) {
            $this->variantSelection[$key] = $data[$key] ?? [];
            unset($data[$key]);
        }

        return $data;
    }

    protected function afterCreate(): void
    {
        /** @var Product $product */
        $product = $this->getRecord();

        $result = VariantMatrix::sync(
            $product,
            $this->variantSelection['variant_color_ids'] ?? [],
            $this->variantSelection['variant_size_ids'] ?? [],
            $this->variantSelection['variant_secondary_size_ids'] ?? [],
        );

        if ($result['created'] === 0) {
            return;
        }

        Notification::make()
            ->title('Variants created')
            ->body("{$result['created']} variants generated. Set their stock in the Inventory tab.")
            ->success()
            ->send();
    }
}

<?php

namespace App\Filament\Resources\Products\Pages;

use App\Filament\Resources\Products\ProductResource;
use App\Models\Product;
use App\Models\Size;
use App\Support\VariantMatrix;
use App\Support\VariantPricing;
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

    /** Submitted per-size prices, applied after save. @var array<int, int|null> */
    protected array $sizePrices = [];

    /**
     * The per-size prices the form was filled with. Saving compares against
     * this so an untouched size is left alone — that is what stops a product
     * save from flattening a variant somebody priced by hand.
     *
     * @var array<int, int|null>
     */
    protected array $sizePricesOriginal = [];

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

        $this->sizePricesOriginal = VariantPricing::bySize($product);
        $mixed = VariantPricing::mixedSizes($product);

        $labels = Size::whereIn('id', array_keys($this->sizePricesOriginal))
            ->orderBy('position')
            ->pluck('name', 'id');

        $rows = [];

        foreach ($labels as $sizeId => $label) {
            $rows[] = [
                'size_id' => $sizeId,
                'size_label' => $label,
                'price_cents' => $this->sizePricesOriginal[$sizeId] ?? null,
                // Say so rather than showing a blank that looks like "no
                // override" and would overwrite the difference on save.
                'note' => in_array($sizeId, $mixed, true)
                    ? 'Colours priced differently — entering a price here unifies them.'
                    : '',
            ];
        }

        return [
            ...$data,
            ...VariantMatrix::currentSelection($product),
            'size_prices' => $rows,
        ];
    }

    protected function mutateFormDataBeforeSave(array $data): array
    {
        foreach (['variant_color_ids', 'variant_size_ids', 'variant_secondary_size_ids'] as $key) {
            $this->variantSelection[$key] = $data[$key] ?? [];
            unset($data[$key]);
        }

        $this->sizePrices = [];

        foreach ($data['size_prices'] ?? [] as $row) {
            if (isset($row['size_id'])) {
                $this->sizePrices[(int) $row['size_id']] = $row['price_cents'] ?? null;
            }
        }

        unset($data['size_prices']);

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

        // Pricing runs after the matrix so a size added in the same save is
        // already there to be priced.
        $repriced = VariantPricing::apply($product, $this->sizePrices, $this->sizePricesOriginal);

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

        if ($repriced) {
            $parts[] = "{$repriced} repriced";
        }

        if ($parts === []) {
            return;
        }

        Notification::make()
            ->title('Variants updated')
            ->body(implode(', ', $parts).'.')
            ->success()
            ->send();
    }
}

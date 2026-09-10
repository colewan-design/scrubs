<?php

namespace Database\Seeders;

use App\Models\Category;
use App\Models\Color;
use App\Models\InventoryMovement;
use App\Models\Product;
use App\Models\ProductImage;
use App\Models\ProductVariant;
use App\Models\Size;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Str;

/**
 * PLACEHOLDER catalogue so the real layouts can be reviewed before the client's
 * photography and product data arrive (§14). Every product here is fictional.
 *
 * The client's launch catalogue replaces this entirely — nothing in this seeder
 * should reach production.
 *
 * Imagery comes from web/public/placeholders/products.json, which is scraped
 * reference photography, NOT licensed artwork. It exists so the grid, the
 * gallery and the colour-swatch switching can be reviewed against real photos
 * instead of grey boxes. It must be replaced before launch.
 */
class CatalogSeeder extends Seeder
{
    /** Colourways per product. Enough to fill the card's swatch row without overflow. */
    protected const MAX_COLORS = 5;

    /**
     * Catalogue product_type -> manifest categories holding suitable shots, in
     * preference order.
     *
     * The models in these shots wear a full outfit, so a top or bottom photo
     * still reads as a set. That spill-over is what lets two set products be
     * illustrated by different garments — the "scrubs" bucket alone is too thin.
     * Outerwear is deliberately absent: a fleece jacket does not read as scrubs.
     */
    protected const POOLS_FOR_TYPE = [
        'set' => ['scrubs', 'tops', 'pants'],
        'top' => ['tops'],
        'bottom' => ['pants'],
    ];

    /**
     * Source garments whose styling is too specific to stand in for a generic
     * catalogue product — a visibly pregnant model reads as a maternity line.
     */
    protected const EXCLUDE_SOURCES = '/maternity|jumpsuit|leggings/';

    /** Manifest entries grouped as "{gender}/{category}" => colour name => entries. */
    protected array $pools = [];

    /** Source garments already illustrating a product, keyed by manifest id. */
    protected array $used = [];

    public function run(): void
    {
        $this->loadImagePools();

        $women = Category::updateOrCreate(
            ['slug' => 'women'],
            [
                'name' => "Women's Scrubs",
                'description' => 'Scrub sets, tops and bottoms designed for a woman’s fit.',
                'position' => 1,
                'is_active' => true,
            ]
        );

        $men = Category::updateOrCreate(
            ['slug' => 'men'],
            [
                'name' => "Men's Scrubs",
                'description' => 'Scrub sets, tops and bottoms designed for a man’s fit.',
                'position' => 2,
                'is_active' => true,
            ]
        );

        // Ordered by how narrow each product's image pool is, not for display:
        // tops and bottoms draw on one bucket each and get first pick, then the
        // sets take what is left across all three. Seeding the sets first let
        // them strip the tops bucket bare.
        $products = [
            [$women, 'Everyday V-Neck Top', 'BSD-W-VNK', 3200, 'top'],
            [$men, 'Everyday Scrub Top', 'BSD-M-TOP', 3200, 'top'],
            [$women, 'Stretch Cargo Pant', 'BSD-W-CRG', 3800, 'bottom'],
            [$men, 'Straight Leg Scrub Pant', 'BSD-M-STR', 3800, 'bottom'],
            [$women, 'Classic Scrub Set', 'BSD-W-CLS', 6500, 'set'],
            [$women, 'Jogger Scrub Set', 'BSD-W-JOG', 7200, 'set'],
            [$men, 'Classic Scrub Set', 'BSD-M-CLS', 6500, 'set'],
            [$men, 'Utility Scrub Set', 'BSD-M-UTL', 7400, 'set'],
        ];

        $sizes = Size::query()->orderBy('position')->get();
        $fallbackColors = Color::query()->orderBy('position')->take(self::MAX_COLORS)->get();

        foreach ($products as [$category, $name, $sku, $priceCents, $type]) {
            $isSet = $type === 'set';
            $gender = $category->slug === 'women' ? 'womens' : 'mens';
            $poolKeys = array_map(
                fn (string $c) => $gender.'/'.$c,
                self::POOLS_FOR_TYPE[$type]
            );

            $product = Product::updateOrCreate(
                ['base_sku' => $sku],
                [
                    'category_id' => $category->id,
                    'name' => $name,
                    'slug' => Str::slug($category->slug.'-'.$name),
                    'product_type' => $type,
                    // Open question Q1 — the recommended default is that a set is
                    // sized independently top and bottom. Placeholder data follows
                    // that assumption so the UI is exercised against it.
                    'has_dual_sizing' => false,
                    'short_description' => 'Durable, breathable fabric built for long shifts.',
                    'description' => 'Placeholder description. Final product copy is to be supplied by BulkScrubsDirect before launch.',
                    'materials' => '55% cotton, 45% polyester twill. Machine washable.',
                    'dimensions_fit' => 'Regular fit. Model is 5\'8" and wears a size S.',
                    'retail_price_cents' => $priceCents,
                    'wholesale_base_price_cents' => (int) round($priceCents * 0.7),
                    'is_active' => true,
                    'is_featured' => $isSet,
                    'published_at' => now(),
                ]
            );

            // A colour only earns a variant if the pool can illustrate it, so the
            // swatches on the PDP always switch the gallery to a matching photo.
            $assignments = $this->assignmentsFor($poolKeys);
            $colors = $assignments === []
                ? $fallbackColors
                : Color::query()->whereIn('slug', array_keys($assignments))->get();

            // A colourway in the manifest with no Color row is dropped by the
            // whereIn above, silently costing the product a swatch. Say so —
            // the fix is to add the colour in FoundationSeeder.
            if ($assignments !== [] && $colors->count() !== count($assignments)) {
                $absent = array_diff(array_keys($assignments), $colors->pluck('slug')->all());
                $this->command?->warn(
                    "{$name} ({$gender}): no Color row for [".implode(', ', $absent).'] — swatch dropped.'
                );
            }

            $this->seedImages($product, $colors, $assignments);

            foreach ($colors as $color) {
                foreach ($sizes as $size) {
                    $variant = ProductVariant::updateOrCreate(
                        [
                            'product_id' => $product->id,
                            'color_id' => $color->id,
                            'size_id' => $size->id,
                            'secondary_size_id' => null,
                        ],
                        [
                            'sku' => "{$sku}-".strtoupper(substr($color->slug, 0, 3))."-{$size->name}",
                            // Deliberately uneven, including a couple of zeroes,
                            // so out-of-stock states get exercised in the UI.
                            'stock_qty' => match ($size->name) {
                                'XS', '3XL' => 0,
                                'M', 'L' => rand(20, 60),
                                default => rand(4, 25),
                            },
                            'reserved_qty' => 0,
                            'weight_grams' => $product->product_type === 'set' ? 480 : 260,
                            'is_active' => true,
                        ]
                    );

                    if ($variant->wasRecentlyCreated && $variant->stock_qty > 0) {
                        InventoryMovement::create([
                            'product_variant_id' => $variant->id,
                            'delta' => $variant->stock_qty,
                            'balance_after' => $variant->stock_qty,
                            'reason' => InventoryMovement::REASON_INITIAL,
                            'note' => 'Seeded placeholder stock',
                        ]);
                    }
                }
            }

            // An earlier seed run used a different palette. Those variants would
            // still paint a swatch on the grid — the index endpoint loads variants
            // without filtering on is_active — but no image backs them, so the
            // colour would switch the gallery to nothing. Cart lines and stock
            // movements cascade; order lines null out and keep their snapshot.
            $product->variants()
                ->whereNotIn('color_id', $colors->pluck('id'))
                ->delete();
        }

        // Local admin account for the Filament panel. Credentials are rotated at
        // handover (§12) — this exists for development only.
        User::updateOrCreate(
            ['email' => 'admin@bulkscrubsdirect.test'],
            [
                'name' => 'BulkScrubsDirect Admin',
                'password' => 'password',
                'role' => 'admin',
                'status' => 'active',
                'email_verified_at' => now(),
            ]
        );
    }

    /**
     * Read the placeholder manifest that ships in the Nuxt public directory and
     * bucket it by audience and garment type.
     *
     * A missing manifest is not fatal: the catalogue still seeds, products just
     * fall back to the old imageless state and the grid shows "Image to follow".
     */
    protected function loadImagePools(): void
    {
        $path = base_path('../web/public/placeholders/products.json');

        if (! is_file($path)) {
            $this->command?->warn("Placeholder manifest not found at {$path} — seeding without imagery.");

            return;
        }

        $manifest = json_decode((string) file_get_contents($path), true);

        foreach ($manifest['products'] ?? [] as $entry) {
            if (($entry['color'] ?? null) === null || ($entry['files'] ?? []) === []) {
                continue;
            }

            if (preg_match(self::EXCLUDE_SOURCES, $entry['id']) === 1) {
                continue;
            }

            $this->pools[$entry['gender'].'/'.$entry['category']][$entry['color']][] = $entry;
        }
    }

    /**
     * Choose this product's colourways: the best-represented colours across the
     * given pools, each resolved to one source garment.
     *
     * @param  array<int, string>  $poolKeys  pool names in preference order
     * @return array<string, array{name: string, files: array<int, string>}> keyed by colour slug
     */
    protected function assignmentsFor(array $poolKeys): array
    {
        $byColor = [];

        foreach ($poolKeys as $poolKey) {
            foreach ($this->pools[$poolKey] ?? [] as $colorName => $entries) {
                $byColor[$colorName] = array_merge($byColor[$colorName] ?? [], $entries);
            }
        }

        if ($byColor === []) {
            return [];
        }

        // Rank on garments still unclaimed rather than on raw pool size, so a
        // second product drawing on the same pool shifts onto colourways that
        // can still show it a different photograph. Total size breaks ties.
        $unclaimed = fn (array $entries) => count(
            array_filter($entries, fn (array $e) => ! isset($this->used[$e['id']]))
        );

        uasort(
            $byColor,
            fn (array $a, array $b) => [$unclaimed($b), count($b)] <=> [$unclaimed($a), count($a)]
        );

        $assignments = [];

        foreach (array_slice($byColor, 0, self::MAX_COLORS, true) as $colorName => $entries) {
            // First garment not already illustrating another product. A repeat
            // only happens once the pool is genuinely exhausted, which beats
            // leaving the colourway with no photograph at all.
            $entry = null;

            foreach ($entries as $candidate) {
                if (! isset($this->used[$candidate['id']])) {
                    $entry = $candidate;
                    break;
                }
            }

            $entry ??= $entries[0];
            $this->used[$entry['id']] = true;

            $assignments[Str::slug($colorName)] = [
                'name' => $colorName,
                'files' => $entry['files'],
            ];
        }

        return $assignments;
    }

    /**
     * Rebuild the product's gallery. Rows are deleted first because product_images
     * has no natural key to updateOrCreate against — re-seeding would otherwise
     * stack duplicate galleries.
     *
     * @param  array<string, array{name: string, files: array<int, string>}>  $assignments
     */
    protected function seedImages(Product $product, iterable $colors, array $assignments): void
    {
        $product->images()->delete();

        if ($assignments === []) {
            return;
        }

        $position = 0;

        foreach ($colors as $color) {
            $assignment = $assignments[$color->slug] ?? null;

            if ($assignment === null) {
                continue;
            }

            foreach ($assignment['files'] as $i => $file) {
                ProductImage::create([
                    'product_id' => $product->id,
                    'color_id' => $color->id,
                    'path' => $file,
                    // Placeholder alt text. Real copy is authored in the admin at
                    // upload time — see the note on the product_images migration.
                    'alt_text' => sprintf(
                        '%s in %s, %s',
                        $product->name,
                        $assignment['name'],
                        $i === 0 ? 'front view' : 'back view'
                    ),
                    'position' => $position,
                    'is_primary' => $position === 0,
                ]);

                $position++;
            }
        }
    }
}

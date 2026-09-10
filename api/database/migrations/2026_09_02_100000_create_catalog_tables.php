<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Catalogue: categories, attributes, products, variants.
 *
 * Requirements §2. Structured so additional categories can be added later
 * without a redesign — hence categories.parent_id rather than hardcoded routes.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('size_charts', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->longText('body')->nullable();      // rendered HTML table
            $table->timestamps();
        });

        Schema::create('categories', function (Blueprint $table) {
            $table->id();
            $table->foreignId('parent_id')->nullable()->constrained('categories')->nullOnDelete();
            $table->foreignId('size_chart_id')->nullable()->constrained('size_charts')->nullOnDelete();
            $table->string('name');
            $table->string('slug')->unique();
            $table->text('description')->nullable();
            $table->string('image_path')->nullable();
            $table->string('meta_title')->nullable();
            $table->string('meta_description', 500)->nullable();
            $table->unsignedInteger('position')->default(0);
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->index(['is_active', 'position']);
        });

        Schema::create('colors', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('slug')->unique();
            $table->string('hex', 7)->nullable();       // drives the swatch UI
            $table->unsignedInteger('position')->default(0);
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });

        Schema::create('sizes', function (Blueprint $table) {
            $table->id();
            $table->string('name');                     // XS, S, M, L, XL, 2XL, 3XL
            $table->string('slug')->unique();
            // Sizes must sort by garment order, never alphabetically.
            $table->unsignedInteger('position')->default(0);
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->index('position');
        });

        Schema::create('products', function (Blueprint $table) {
            $table->id();
            $table->foreignId('category_id')->constrained()->restrictOnDelete();
            $table->foreignId('size_chart_id')->nullable()->constrained()->nullOnDelete();

            $table->string('name');
            $table->string('slug')->unique();
            $table->string('base_sku')->unique();

            // Open question Q1 — what a "scrub set" actually is.
            // 'set'    : top + bottom sold together
            // 'top'    : top only
            // 'bottom' : bottom only
            $table->string('product_type')->default('set');
            // When true, the customer picks top and bottom sizes independently,
            // and variants carry a secondary_size_id. Covers all three answers to
            // Q1 without a later migration.
            $table->boolean('has_dual_sizing')->default(false);

            $table->text('short_description')->nullable();
            $table->longText('description')->nullable();      // accordion panel 1 (§2)
            $table->longText('materials')->nullable();        // accordion panel 2
            $table->longText('dimensions_fit')->nullable();   // accordion panel 3

            // Money is always integer cents. Never floats. A variant may override.
            $table->bigInteger('retail_price_cents');
            $table->bigInteger('wholesale_base_price_cents')->nullable();

            $table->unsignedInteger('low_stock_threshold')->default(5);
            $table->boolean('is_active')->default(true);
            $table->boolean('is_featured')->default(false);
            $table->string('meta_title')->nullable();
            $table->string('meta_description', 500)->nullable();
            $table->timestamp('published_at')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->index(['category_id', 'is_active', 'published_at']);
            $table->index(['is_featured', 'is_active']);
            $table->fullText(['name', 'short_description']);   // catalogue search
        });

        Schema::create('product_images', function (Blueprint $table) {
            $table->id();
            $table->foreignId('product_id')->constrained()->cascadeOnDelete();
            // Nullable: lets the gallery switch when a shopper picks a colour.
            $table->foreignId('color_id')->nullable()->constrained()->nullOnDelete();
            $table->string('path');
            // Populated by admin, never auto-generated — WCAG 1.1.1.
            $table->string('alt_text')->nullable();
            $table->unsignedInteger('position')->default(0);
            $table->boolean('is_primary')->default(false);
            $table->timestamps();

            $table->index(['product_id', 'position']);
        });

        Schema::create('product_variants', function (Blueprint $table) {
            $table->id();
            $table->foreignId('product_id')->constrained()->cascadeOnDelete();
            $table->foreignId('color_id')->constrained()->restrictOnDelete();
            $table->foreignId('size_id')->constrained('sizes')->restrictOnDelete();
            // Only used when the parent product has_dual_sizing (Q1 option b):
            // size_id is the top, secondary_size_id the bottom.
            $table->foreignId('secondary_size_id')->nullable()->constrained('sizes')->restrictOnDelete();

            $table->string('sku')->unique();

            // Uniqueness guard for the colour/size combination.
            // A composite unique index cannot be used here: SQL treats NULLs as
            // distinct, so nullable secondary_size_id would let duplicate variants
            // through. This key is derived on save (see ProductVariant::booted).
            $table->string('variant_key', 64)->unique();

            $table->bigInteger('retail_price_cents')->nullable();   // overrides the product price

            // Available to sell = stock_qty - reserved_qty.
            $table->integer('stock_qty')->default(0);
            $table->integer('reserved_qty')->default(0);
            $table->unsignedInteger('low_stock_threshold')->nullable();

            // Required for live Stallion rates (Risk R4). A per-product average is
            // acceptable — size variance is well inside a parcel rate band.
            $table->unsignedInteger('weight_grams')->nullable();
            $table->unsignedInteger('length_mm')->nullable();
            $table->unsignedInteger('width_mm')->nullable();
            $table->unsignedInteger('height_mm')->nullable();

            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->index(['product_id', 'color_id', 'size_id'], 'variants_combination_index');
            $table->index(['is_active', 'stock_qty']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('product_variants');
        Schema::dropIfExists('product_images');
        Schema::dropIfExists('products');
        Schema::dropIfExists('sizes');
        Schema::dropIfExists('colors');
        Schema::dropIfExists('categories');
        Schema::dropIfExists('size_charts');
    }
};

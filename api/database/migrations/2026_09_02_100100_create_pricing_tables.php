<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Wholesale tier pricing — requirements §3.
 *
 * Thresholds and prices must be editable by the administrator without code
 * changes, so everything here is data rather than configuration.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('pricing_tiers', function (Blueprint $table) {
            $table->id();
            $table->string('name');                 // "Tier 1", or the client's naming
            $table->string('slug')->unique();
            $table->text('description')->nullable();

            // The brief uses "MOQ" and "qualifying order value" interchangeably
            // (open question Q3). Both columns exist so the client's eventual
            // answer is a settings change rather than a migration. Either may be
            // left null; qualify_mode decides how they combine.
            $table->bigInteger('min_subtotal_cents')->nullable();
            $table->unsignedInteger('min_qty')->nullable();
            $table->string('qualify_mode')->default('any');   // 'any' (OR) | 'all' (AND)

            // 'percent'          — discount_value in basis points (1000 = 10%)
            // 'fixed_amount_off' — discount_value in cents, off each unit
            // 'absolute_price'   — discount_value in cents, becomes the unit price
            $table->string('discount_type')->default('percent');
            $table->bigInteger('discount_value')->default(0);

            $table->unsignedInteger('priority')->default(0);  // highest qualifying wins
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->index(['is_active', 'priority']);
        });

        // Per-product wholesale prices, for products whose price does not follow
        // the catalogue-wide rule. The brief's "$65 retail / $45 wholesale" is
        // exactly this shape.
        Schema::create('product_tier_prices', function (Blueprint $table) {
            $table->id();
            $table->foreignId('product_id')->constrained()->cascadeOnDelete();
            $table->foreignId('pricing_tier_id')->constrained()->cascadeOnDelete();
            $table->bigInteger('price_cents');
            $table->timestamps();

            $table->unique(['product_id', 'pricing_tier_id']);
        });

        // V2 seam (§13 "custom pricing for very large wholesale accounts").
        // Not exposed at launch, but the resolution order in PricingService
        // already checks it, so adding it later is configuration rather than
        // a refactor of the pricing engine.
        Schema::create('customer_price_lists', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->text('notes')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });

        Schema::create('customer_price_list_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('customer_price_list_id')->constrained()->cascadeOnDelete();
            $table->foreignId('product_id')->constrained()->cascadeOnDelete();
            $table->bigInteger('price_cents');
            $table->unsignedInteger('min_qty')->nullable();
            $table->timestamps();

            $table->unique(['customer_price_list_id', 'product_id'], 'price_list_product_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('customer_price_list_items');
        Schema::dropIfExists('customer_price_lists');
        Schema::dropIfExists('product_tier_prices');
        Schema::dropIfExists('pricing_tiers');
    }
};

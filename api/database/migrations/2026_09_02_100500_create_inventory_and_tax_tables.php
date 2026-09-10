<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Inventory ledger (§2, §7) and Canadian tax rates (§6).
 */
return new class extends Migration
{
    public function up(): void
    {
        /**
         * Append-only ledger. product_variants.stock_qty is the running total;
         * this table explains every change to it. When stock is wrong, this is
         * how anyone finds out why.
         */
        Schema::create('inventory_movements', function (Blueprint $table) {
            $table->id();
            $table->foreignId('product_variant_id')->constrained()->cascadeOnDelete();
            $table->integer('delta');                        // signed
            $table->integer('balance_after');
            // purchase | manual_adjustment | refund_restock | cancellation | correction | initial
            $table->string('reason');
            $table->nullableMorphs('reference');            // the order, refund, etc.
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->text('note')->nullable();
            $table->timestamps();

            $table->index(['product_variant_id', 'created_at']);
        });

        /**
         * Effective-dated so a rate change is a new row, and reprinting an old
         * invoice still shows the tax that was actually charged.
         *
         * Which provinces the client is *registered* to collect in is a business
         * decision requiring their accountant (open question Q6) — hence is_active
         * per row rather than a hardcoded rate table.
         */
        Schema::create('tax_rates', function (Blueprint $table) {
            $table->id();
            $table->string('province', 2);
            $table->string('tax_type', 8);                   // GST|HST|PST|QST
            $table->unsignedInteger('rate_bps');             // 1300 = 13%
            $table->string('applies_to')->default('goods');  // goods|shipping
            $table->boolean('is_active')->default(true);
            $table->date('effective_from')->nullable();
            $table->date('effective_to')->nullable();
            $table->timestamps();

            $table->index(['province', 'tax_type', 'is_active']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('tax_rates');
        Schema::dropIfExists('inventory_movements');
    }
};

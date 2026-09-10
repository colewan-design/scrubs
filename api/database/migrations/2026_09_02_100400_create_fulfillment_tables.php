<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Payments, refunds, shipments and table shipping rates — §4, §5.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('payments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('order_id')->constrained()->cascadeOnDelete();
            $table->string('provider');                     // stripe|etransfer|manual
            $table->string('provider_reference')->nullable();
            $table->string('method')->nullable();           // card|paypal|apple_pay|google_pay|etransfer
            $table->string('card_brand')->nullable();
            $table->string('card_last_four', 4)->nullable();
            // Card details are never stored — only a provider reference and the
            // last four digits (§12, PCI SAQ-A).
            $table->string('status')->default('pending');   // pending|succeeded|failed|cancelled
            $table->bigInteger('amount_cents');
            $table->string('currency', 3)->default('CAD');
            $table->json('raw_response')->nullable();
            $table->timestamp('paid_at')->nullable();
            $table->timestamps();

            $table->index(['order_id', 'status']);
            $table->index('provider_reference');
        });

        Schema::create('refunds', function (Blueprint $table) {
            $table->id();
            $table->foreignId('payment_id')->constrained()->cascadeOnDelete();
            $table->foreignId('order_id')->constrained()->cascadeOnDelete();
            $table->bigInteger('amount_cents');
            $table->text('reason')->nullable();
            $table->string('provider_reference')->nullable();
            $table->boolean('restock')->default(true);
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index('order_id');
        });

        // Multiple rows per order allow split shipments later without a redesign.
        Schema::create('shipments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('order_id')->constrained()->cascadeOnDelete();
            $table->string('carrier')->nullable();
            $table->string('service')->nullable();
            $table->string('tracking_number')->nullable();
            $table->string('tracking_url', 500)->nullable();
            $table->bigInteger('cost_cents')->nullable();
            $table->string('label_path')->nullable();
            $table->string('provider')->default('manual');   // stallion|manual
            $table->string('provider_shipment_id')->nullable();
            $table->unsignedInteger('weight_grams')->nullable();
            $table->string('status')->default('pending');
            $table->timestamp('shipped_at')->nullable();
            $table->timestamp('delivered_at')->nullable();
            $table->timestamps();

            $table->index('order_id');
            $table->index('tracking_number');
        });

        /**
         * Table-rate shipping. Built first and kept permanently as the fallback,
         * so checkout is never blocked on the Stallion integration and degrades
         * gracefully if their API is slow or unavailable at checkout time (§5).
         */
        Schema::create('shipping_zones', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->json('provinces');                       // ["ON","QC"] — [] means rest of Canada
            $table->unsignedInteger('position')->default(0);
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });

        Schema::create('shipping_rates', function (Blueprint $table) {
            $table->id();
            $table->foreignId('shipping_zone_id')->constrained()->cascadeOnDelete();
            $table->string('name');                          // "Standard", "Expedited"
            $table->unsignedInteger('min_weight_grams')->nullable();
            $table->unsignedInteger('max_weight_grams')->nullable();
            $table->bigInteger('min_subtotal_cents')->nullable();
            $table->bigInteger('max_subtotal_cents')->nullable();
            $table->bigInteger('rate_cents')->default(0);
            $table->boolean('is_free')->default(false);
            $table->unsignedInteger('delivery_days_min')->nullable();
            $table->unsignedInteger('delivery_days_max')->nullable();
            $table->unsignedInteger('position')->default(0);
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->index(['shipping_zone_id', 'is_active']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('shipping_rates');
        Schema::dropIfExists('shipping_zones');
        Schema::dropIfExists('shipments');
        Schema::dropIfExists('refunds');
        Schema::dropIfExists('payments');
    }
};

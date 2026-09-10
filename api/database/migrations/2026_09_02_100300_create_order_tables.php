<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Orders — requirements §7.
 *
 * The brief lists eight customer-facing statuses, but those conflate payment
 * state with fulfillment state ("Refunded" is a payment fact, "Shipped" a
 * fulfillment fact, and an order can be both). Two fields are stored and the
 * single customer-facing label is derived from them by OrderService, so the
 * client keeps exactly their vocabulary while the system stays correct about
 * a shipped-then-refunded order.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('orders', function (Blueprint $table) {
            $table->id();
            // Human-readable. The raw database id is never exposed.
            $table->string('order_number', 32)->unique();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();

            // Snapshot at purchase — an account email change must not rewrite history.
            $table->string('email');
            $table->string('phone')->nullable();

            $table->string('status')->default('pending_payment');       // derived, for display
            $table->string('payment_status')->default('pending');       // pending|paid|partially_refunded|refunded|failed
            $table->string('fulfillment_status')->default('unfulfilled');
            $table->string('fulfillment_type')->default('ship');        // ship|pickup (§5)

            $table->foreignId('pricing_tier_id')->nullable()->constrained()->nullOnDelete();
            $table->string('pricing_tier_name')->nullable();            // snapshot

            $table->bigInteger('subtotal_cents')->default(0);           // retail, pre-discount
            $table->bigInteger('discount_cents')->default(0);           // wholesale saving
            $table->bigInteger('shipping_cents')->default(0);
            $table->bigInteger('tax_cents')->default(0);
            $table->bigInteger('grand_total_cents')->default(0);
            $table->string('currency', 3)->default('CAD');              // §4

            $table->text('customer_note')->nullable();
            $table->text('admin_notes')->nullable();

            $table->timestamp('placed_at')->nullable();
            $table->timestamp('paid_at')->nullable();
            $table->timestamp('shipped_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->timestamp('cancelled_at')->nullable();
            $table->timestamps();

            $table->index(['user_id', 'created_at']);
            $table->index(['status', 'created_at']);
            $table->index('email');
        });

        /**
         * Immutable price snapshot. The duplication is intentional: editing a
         * price or renaming a product in admin must never alter a historical
         * order or a previously issued invoice.
         */
        Schema::create('order_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('order_id')->constrained()->cascadeOnDelete();
            $table->foreignId('product_variant_id')->nullable()->constrained()->nullOnDelete();

            $table->string('product_name');
            $table->string('variant_sku');
            $table->string('color_name')->nullable();
            $table->string('size_name')->nullable();
            $table->string('secondary_size_name')->nullable();

            $table->unsignedInteger('qty');
            $table->bigInteger('unit_retail_cents');       // what it would have cost at retail
            $table->bigInteger('unit_price_cents');        // what was actually charged
            $table->bigInteger('line_discount_cents')->default(0);
            $table->bigInteger('line_total_cents');
            $table->string('pricing_tier_name')->nullable();
            $table->timestamps();

            $table->index('order_id');
            $table->index('product_variant_id');
        });

        Schema::create('order_addresses', function (Blueprint $table) {
            $table->id();
            $table->foreignId('order_id')->constrained()->cascadeOnDelete();
            $table->string('type');                        // shipping|billing
            $table->string('first_name');
            $table->string('last_name');
            $table->string('company')->nullable();
            $table->string('line1');
            $table->string('line2')->nullable();
            $table->string('city');
            $table->string('province', 2);                 // ON, BC, QC …
            $table->string('postal_code', 10);
            $table->string('country', 2)->default('CA');
            $table->string('phone')->nullable();
            $table->timestamps();

            $table->unique(['order_id', 'type']);
        });

        // One row per applied tax so checkout and invoices can itemise
        // ("HST 13% — $8.45") exactly as §6 requires.
        Schema::create('order_taxes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('order_id')->constrained()->cascadeOnDelete();
            $table->string('tax_type', 8);                 // GST|HST|PST|QST
            $table->string('province', 2);
            $table->unsignedInteger('rate_bps');            // 1300 = 13%
            $table->bigInteger('taxable_base_cents');
            $table->bigInteger('amount_cents');
            $table->timestamps();

            $table->index('order_id');
        });

        // Feeds the customer's order timeline and gives admin an audit trail (§7, §9).
        Schema::create('order_status_history', function (Blueprint $table) {
            $table->id();
            $table->foreignId('order_id')->constrained()->cascadeOnDelete();
            $table->string('from_status')->nullable();
            $table->string('to_status');
            $table->foreignId('changed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->text('note')->nullable();
            $table->boolean('notified_customer')->default(false);
            $table->timestamps();

            $table->index(['order_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('order_status_history');
        Schema::dropIfExists('order_taxes');
        Schema::dropIfExists('order_addresses');
        Schema::dropIfExists('order_items');
        Schema::dropIfExists('orders');
    }
};

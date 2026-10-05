<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Which delivery service the customer chose and paid for (§5).
 *
 * Until now an order recorded only what shipping cost. With two table rates
 * that was enough — $15 was Standard and $29 was Express — but a live carrier
 * rate is a different figure for every address, and "$18.42" does not say
 * whether the customer paid for next-day or for the slow boat.
 *
 * Snapshotted like everything else on the order:
 *   shipping_method       — what the customer picked: "Standard", "Express".
 *   shipping_carrier      — who that was on the day: "Canada Post". Null for a
 *                           table rate, which names no carrier.
 *   shipping_service      — the carrier's service: "Expedited Parcel".
 *   shipping_service_code — the carrier's own code for it, which is what
 *                           buying the label asks for.
 *
 * All four are NULL on orders placed before these columns existed.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->string('shipping_method', 120)->nullable()->after('fulfillment_type');
            $table->string('shipping_carrier', 120)->nullable()->after('shipping_method');
            $table->string('shipping_service', 120)->nullable()->after('shipping_carrier');
            $table->string('shipping_service_code', 120)->nullable()->after('shipping_service');
        });
    }

    public function down(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->dropColumn([
                'shipping_method', 'shipping_carrier', 'shipping_service', 'shipping_service_code',
            ]);
        });
    }
};

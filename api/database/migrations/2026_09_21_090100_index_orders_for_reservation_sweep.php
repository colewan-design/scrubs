<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Support the abandoned-order sweep, which runs every five minutes forever.
 *
 * `orders:release-abandoned` filters on payment_status + fulfillment_status +
 * placed_at. None of the existing indexes cover that combination, so without
 * this the sweep is a full table scan — cheap on a new store and steadily less
 * so, on a schedule frequent enough that it would eventually be noticed as
 * "the database gets busy every five minutes" long after anyone connects it to
 * this command.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->index(
                ['payment_status', 'fulfillment_status', 'placed_at'],
                'orders_unsettled_index',
            );
        });
    }

    public function down(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->dropIndex('orders_unsettled_index');
        });
    }
};

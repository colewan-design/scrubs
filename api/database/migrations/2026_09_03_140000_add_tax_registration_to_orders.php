<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The seller's tax registration number, frozen onto the order — §6's "business
 * tax registration information should be addable to invoices/receipts".
 *
 * Snapshotted rather than read live from settings for the same reason the
 * address and the unit prices are: a receipt is a record of what was true when
 * the money changed hands. If the client registers for GST in March, the
 * receipts from February must not retroactively grow a number the business did
 * not hold at the time.
 *
 * The distinction between NULL and '' carries that meaning:
 *   NULL — placed before this column existed; fall back to the current setting,
 *          which is the best information available for those orders.
 *   ''   — placed while the business had no registration number. Show nothing.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->string('tax_registration', 40)->nullable()->after('currency');
        });
    }

    public function down(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->dropColumn('tax_registration');
        });
    }
};

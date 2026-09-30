<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Separate the audit trail from the customer's timeline.
 *
 * Both have always been the same rows — deliberately, so a status change cannot
 * be recorded in one place and missed in the other (§7, rule 4). That stops
 * working the moment a note is written that the customer must not read, and
 * introducing a payment provider creates exactly those notes: "Stripe reported
 * $84.20 against a total of $94.92", "payment received after cancellation,
 * refund required".
 *
 * So the rows stay shared and one column decides who sees the note. Existing
 * history is customer-visible, which is what it already was.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('order_status_history', function (Blueprint $table) {
            $table->boolean('is_internal')->default(false)->after('note');
        });
    }

    public function down(): void
    {
        Schema::table('order_status_history', function (Blueprint $table) {
            $table->dropColumn('is_internal');
        });
    }
};

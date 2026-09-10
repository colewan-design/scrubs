<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Customer account fields — §3 and §8.
 *
 * Registration is deliberately short: the brief's priority is "a fast,
 * low-friction signup", so only name, email, phone and password are required.
 * Business name and location are optional, and notably the customer does NOT
 * need to prove they operate a registered business in order to buy at
 * wholesale — the MOQ is the qualification.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->string('phone')->nullable()->after('email');

            // Optional (§3) — collected for context, never used for gating.
            $table->string('business_name')->nullable()->after('phone');
            $table->string('city')->nullable()->after('business_name');
            $table->string('province', 2)->nullable()->after('city');

            $table->string('role')->default('customer')->after('province');   // customer|admin|staff
            $table->string('status')->default('active')->after('role');       // active|suspended (§9)

            // V2 seam for §13 "custom pricing for very large wholesale accounts".
            // PricingService already resolves this before global tiers.
            $table->foreignId('customer_price_list_id')->nullable()->after('status')
                ->constrained()->nullOnDelete();

            $table->timestamp('last_login_at')->nullable();
            $table->softDeletes();

            $table->index(['role', 'status']);
        });

        // Google only (§8). `provider` is a plain string so a second one is a driver
        // and a config slot, not a migration.
        Schema::create('social_accounts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('provider');                // google
            $table->string('provider_user_id');
            $table->string('avatar_url', 500)->nullable();
            $table->timestamps();

            $table->unique(['provider', 'provider_user_id']);
            $table->index('user_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('social_accounts');

        Schema::table('users', function (Blueprint $table) {
            $table->dropForeign(['customer_price_list_id']);
            $table->dropIndex(['role', 'status']);
            $table->dropColumn([
                'phone', 'business_name', 'city', 'province', 'role', 'status',
                'customer_price_list_id', 'last_login_at', 'deleted_at',
            ]);
        });
    }
};

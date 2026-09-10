<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Settings (§9 — "manageable without code changes") and saved customer
 * addresses (§8).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('settings', function (Blueprint $table) {
            $table->id();
            $table->string('key')->unique();
            $table->json('value')->nullable();
            $table->string('type')->default('string');   // string|int|bool|money|json
            $table->string('group')->default('general'); // groups the admin UI
            $table->text('description')->nullable();
            $table->timestamps();

            $table->index('group');
        });

        Schema::create('addresses', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('label')->nullable();          // "Home", "Clinic"
            $table->string('first_name');
            $table->string('last_name');
            $table->string('company')->nullable();
            $table->string('line1');
            $table->string('line2')->nullable();
            $table->string('city');
            $table->string('province', 2);
            $table->string('postal_code', 10);
            $table->string('country', 2)->default('CA');
            $table->string('phone')->nullable();
            $table->boolean('is_default_shipping')->default(false);
            $table->boolean('is_default_billing')->default(false);
            $table->timestamps();

            $table->index('user_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('addresses');
        Schema::dropIfExists('settings');
    }
};

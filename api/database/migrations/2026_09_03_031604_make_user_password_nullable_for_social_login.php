<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * A customer who signs in with Google never chooses a password (§3).
 *
 * Storing a random one instead would be worse than storing none: it looks like
 * a credential, it would be accepted by a password reset, and it hides the fact
 * that the account has no password to begin with. NULL says exactly what is
 * true — this account authenticates through a provider.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->string('password')->nullable()->change();
        });
    }

    public function down(): void
    {
        // Password-less accounts cannot survive the column becoming NOT NULL.
        // Giving them an unusable hash keeps the rollback from failing while
        // leaving the account unable to sign in with a password, which is the
        // state it was already in.
        DB::table('users')->whereNull('password')->update([
            'password' => '!social-login-no-password!',
        ]);

        Schema::table('users', function (Blueprint $table) {
            $table->string('password')->nullable(false)->change();
        });
    }
};
